<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\BestSellerGraphQl\Model;

use Magento\Framework\App\ResourceConnection;

/**
 * Reads and ranks best-selling products from the sales_bestsellers_aggregated_*
 * report tables.
 *
 * Magento's aggregated best-seller tables hold a per-store/per-period
 * (store_id, product_id, qty_ordered) breakdown but have NO category dimension,
 * so per-category ranking is derived here by joining catalog_category_product.
 * The aggregation cron (Reports) must have run for these tables to be populated.
 */
class BestSellerProvider
{
    /** How many of a product's categories to consider when computing its badge. */
    private const MAX_CATEGORIES_PER_PRODUCT = 50;

    /**
     * @param ResourceConnection $resource
     */
    public function __construct(
        private readonly ResourceConnection $resource
    ) {
    }

    /**
     * Return the top best-selling products, optionally scoped to a category.
     *
     * @param int $storeId
     * @param int|null $categoryId
     * @param int $limit
     * @param string $period day|month|year
     * @return array<int, array{product_id: int, qty_ordered: float, rank: int}>
     */
    public function getBestSellers(int $storeId, ?int $categoryId, int $limit, string $period): array
    {
        $connection = $this->resource->getConnection();
        $table = $this->resolveAggregatedTable($period);

        // The aggregated report tables may not exist yet (the Reports
        // best-sellers aggregation has never run). Degrade to an empty list
        // rather than throwing — never let a missing table break a page.
        if ($table === null) {
            return [];
        }

        try {
            $select = $connection->select()
                ->from(['bs' => $table], ['product_id' => 'bs.product_id', 'qty' => 'SUM(bs.qty_ordered)'])
                ->where('bs.store_id = ?', $storeId)
                ->group('bs.product_id')
                ->order('qty DESC')
                ->limit($limit);

            if ($categoryId !== null && $categoryId > 0) {
                // Anchor-aware: include products in the category AND all of its
                // descendants, so a top-level (parent) tab shows the best sellers
                // across its whole subtree — products are usually assigned to
                // child/leaf categories, not the parent.
                $subtreeIds = $this->getCategorySubtreeIds($categoryId);
                if (!$subtreeIds) {
                    return [];
                }

                // Distinct product membership across the subtree, joined once, so
                // SUM(qty_ordered) isn't multiplied when a product belongs to
                // several descendant categories.
                $members = $connection->select()
                    ->distinct()
                    ->from(
                        $this->resource->getTableName('catalog_category_product'),
                        ['product_id']
                    )
                    ->where('category_id IN (?)', $subtreeIds);

                $select->join(['cm' => $members], 'cm.product_id = bs.product_id', []);
            }

            $rows = $connection->fetchAll($select);
        } catch (\Exception $e) {
            return [];
        }

        $items = [];
        $rank = 0;
        foreach ($rows as $row) {
            $items[] = [
                'product_id' => (int) $row['product_id'],
                'qty_ordered' => (float) $row['qty'],
                'rank' => ++$rank,
            ];
        }

        return $items;
    }

    /**
     * Compute the best per-category best-seller rank for a single product.
     *
     * Returns the lowest (best) rank the product holds across the categories it
     * belongs to, but only when that rank is at or above the supplied threshold;
     * otherwise null (the product does not earn a badge).
     *
     * @param int $storeId
     * @param int $productId
     * @param int $maxRank
     * @param string $period day|month|year
     * @return array{rank: int, category_id: int}|null
     */
    public function getBadge(int $storeId, int $productId, int $maxRank, string $period): ?array
    {
        $connection = $this->resource->getConnection();
        $table = $this->resolveAggregatedTable($period);

        // No aggregated data yet (the report has never been aggregated): no
        // badge. This is the common case on a fresh store and MUST NOT throw —
        // the badge rides the product detail query, so a thrown exception would
        // 404 the whole product page.
        if ($table === null) {
            return null;
        }

        try {
            // The product's own quantity sold over the period.
            $myQty = (float) $connection->fetchOne(
                $connection->select()
                    ->from($table, [new \Zend_Db_Expr('SUM(qty_ordered)')])
                    ->where('store_id = ?', $storeId)
                    ->where('product_id = ?', $productId)
            );

            if ($myQty <= 0) {
                return null;
            }

            // The navigable categories the product belongs to (skip the root/store-root
            // levels 0 and 1, which aren't real merchandising categories).
            $ccpTable = $this->resource->getTableName('catalog_category_product');
            $categoryIds = $connection->fetchCol(
                $connection->select()
                    ->from(['ccp' => $ccpTable], ['ccp.category_id'])
                    ->join(
                        ['cce' => $this->resource->getTableName('catalog_category_entity')],
                        'cce.entity_id = ccp.category_id',
                        []
                    )
                    ->where('ccp.product_id = ?', $productId)
                    ->where('cce.level >= ?', 2)
                    ->limit(self::MAX_CATEGORIES_PER_PRODUCT)
            );

            if (!$categoryIds) {
                return null;
            }

            // Products in those categories that sold MORE than this product.
            $higherQty = $connection->select()
                ->from(['bs' => $table], ['product_id' => 'bs.product_id'])
                ->where('bs.store_id = ?', $storeId)
                ->group('bs.product_id')
                ->having('SUM(bs.qty_ordered) > ?', $myQty);

            // Per category, the count of higher-selling products => rank = count + 1.
            $rankSelect = $connection->select()
                ->from(
                    ['ccp' => $ccpTable],
                    ['category_id' => 'ccp.category_id', 'higher' => 'COUNT(DISTINCT ccp.product_id)']
                )
                ->join(['hq' => $higherQty], 'hq.product_id = ccp.product_id', [])
                ->where('ccp.category_id IN (?)', $categoryIds)
                ->group('ccp.category_id');

            $best = null;
            $rankRows = $connection->fetchAll($rankSelect);
            foreach ($rankRows as $row) {
                $rank = (int) $row['higher'] + 1;
                if ($rank <= $maxRank && ($best === null || $rank < $best['rank'])) {
                    $best = ['rank' => $rank, 'category_id' => (int) $row['category_id']];
                }
            }

            // A category in which no other product outsold this one isn't returned by
            // the join above (COUNT over an empty set), so cover the rank-1 case: if
            // the product belongs to any of its categories and none outsold it there,
            // it is #1. fetchAll only yields categories with >=1 higher seller, so a
            // missing category means rank 1.
            if ($best === null || $best['rank'] > 1) {
                $rankedCategoryIds = array_column($rankRows, 'category_id');
                $rankOneCategories = array_diff(array_map('intval', $categoryIds), array_map('intval', $rankedCategoryIds));
                if ($rankOneCategories && $maxRank >= 1) {
                    $best = ['rank' => 1, 'category_id' => (int) reset($rankOneCategories)];
                }
            }

            return $best;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Batch variant of {@see getBadge()}: compute best-seller badges for many
     * products at once in a bounded number of queries, so the ProductInterface
     * .best_seller field is safe on listing grids (PRODUCT_CARD_FIELDS) — one
     * pair of queries per grid, not the 3 per product getBadge() would run.
     *
     * The ranking logic is identical to getBadge(): a product's rank in a
     * category is 1 + the number of products in that category that sold strictly
     * more; the product earns the lowest (best) such rank that is at or above the
     * max-rank threshold across the categories it belongs to.
     *
     * @param int $storeId
     * @param int[] $productIds
     * @param int $maxRank
     * @param string $period day|month|year
     * @return array<int, array{rank: int, category_id: int}> keyed by product id
     */
    public function getBadges(int $storeId, array $productIds, int $maxRank, string $period): array
    {
        $productIds = array_values(array_unique(array_filter(array_map('intval', $productIds))));
        if (!$productIds) {
            return [];
        }

        $connection = $this->resource->getConnection();
        $table = $this->resolveAggregatedTable($period);
        if ($table === null) {
            return [];
        }

        try {
            $ccpTable = $this->resource->getTableName('catalog_category_product');
            $cceTable = $this->resource->getTableName('catalog_category_entity');

            // 1. The navigable categories (level >= 2) each target product belongs
            //    to — one query for the whole batch.
            $memberRows = $connection->fetchAll(
                $connection->select()
                    ->from(['ccp' => $ccpTable], ['product_id' => 'ccp.product_id', 'category_id' => 'ccp.category_id'])
                    ->join(['cce' => $cceTable], 'cce.entity_id = ccp.category_id', [])
                    ->where('ccp.product_id IN (?)', $productIds)
                    ->where('cce.level >= ?', 2)
            );

            $productCategories = [];
            $relevantCategories = [];
            foreach ($memberRows as $row) {
                $pid = (int) $row['product_id'];
                $cid = (int) $row['category_id'];
                $productCategories[$pid][] = $cid;
                $relevantCategories[$cid] = true;
            }

            if (!$relevantCategories) {
                return [];
            }

            // 2. The qty sold per (category, product) for every product in the
            //    relevant categories — the data needed to rank within a category.
            //    Grouping by category too means a product in several categories
            //    isn't double-counted (each category gets the product's own total).
            //    One query for the whole batch.
            $qtyRows = $connection->fetchAll(
                $connection->select()
                    ->from(
                        ['ccp' => $ccpTable],
                        ['category_id' => 'ccp.category_id', 'product_id' => 'ccp.product_id', 'qty' => 'SUM(bs.qty_ordered)']
                    )
                    ->join(
                        ['bs' => $table],
                        'bs.product_id = ccp.product_id AND bs.store_id = ' . $connection->quote($storeId),
                        []
                    )
                    ->where('ccp.category_id IN (?)', array_keys($relevantCategories))
                    ->group('ccp.category_id')
                    ->group('ccp.product_id')
            );

            // category_id => [product_id => qty]
            $categoryQty = [];
            foreach ($qtyRows as $row) {
                $categoryQty[(int) $row['category_id']][(int) $row['product_id']] = (float) $row['qty'];
            }

            // 3. Per target product, find its best qualifying rank across its
            //    categories (rank = 1 + higher-selling products in that category).
            $badges = [];
            foreach ($productIds as $pid) {
                $categories = $productCategories[$pid] ?? [];
                $best = null;
                foreach ($categories as $cid) {
                    $qtyMap = $categoryQty[$cid] ?? [];
                    $myQty = $qtyMap[$pid] ?? 0.0;
                    if ($myQty <= 0) {
                        continue; // no sales for this product in the period
                    }
                    $higher = 0;
                    foreach ($qtyMap as $otherPid => $otherQty) {
                        if ($otherPid !== $pid && $otherQty > $myQty) {
                            $higher++;
                        }
                    }
                    $rank = $higher + 1;
                    if ($rank <= $maxRank && ($best === null || $rank < $best['rank'])) {
                        $best = ['rank' => $rank, 'category_id' => $cid];
                    }
                }
                if ($best !== null) {
                    $badges[$pid] = $best;
                }
            }

            return $badges;
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * The category id plus all of its descendant category ids.
     *
     * Uses the materialized `path` column on catalog_category_entity (a
     * slash-separated chain of ancestor ids), so a category with path "1/2/5"
     * matches itself and any descendant whose path starts with "1/2/5/". Returns
     * an empty array when the category does not exist.
     *
     * @param int $categoryId
     * @return int[]
     */
    private function getCategorySubtreeIds(int $categoryId): array
    {
        $connection = $this->resource->getConnection();
        $catTable = $this->resource->getTableName('catalog_category_entity');

        $path = $connection->fetchOne(
            $connection->select()
                ->from($catTable, ['path'])
                ->where('entity_id = ?', $categoryId)
        );

        if (!$path) {
            return [];
        }

        $ids = $connection->fetchCol(
            $connection->select()
                ->from($catTable, ['entity_id'])
                ->where('entity_id = ?', $categoryId)
                ->orWhere('path LIKE ?', $path . '/%')
        );

        return array_map('intval', $ids);
    }

    /**
     * Resolve the aggregated bestsellers table for the given period, or null if
     * it does not exist.
     *
     * Magento's report tables are suffixed daily/monthly/yearly (NOT
     * day/month/year, which is what the admin config stores), so the period
     * value is mapped to the real suffix. Returns null when the table is absent
     * (e.g. the Reports best-sellers aggregation has never run) so callers can
     * degrade gracefully instead of letting a "table not found" error surface.
     *
     * @param string $period
     * @return string|null
     */
    private function resolveAggregatedTable(string $period): ?string
    {
        $suffix = match ($period) {
            'day' => 'daily',
            'year' => 'yearly',
            default => 'monthly',
        };

        $table = $this->resource->getTableName('sales_bestsellers_aggregated_' . $suffix);

        return $this->resource->getConnection()->isTableExists($table) ? $table : null;
    }
}
