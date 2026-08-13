<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\BestSellerGraphQl\Model;

use Magenx\BestSellerGraphQl\Model\Config\Source\Period;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Psr\Log\LoggerInterface;

/**
 * Reads and ranks best-selling products from the sales_bestsellers_aggregated_*
 * report tables.
 *
 * Magento's aggregated best-seller tables hold a per-store/per-period
 * (store_id, product_id, qty_ordered) breakdown but have NO category dimension,
 * so per-category ranking is derived here by joining catalog_category_product.
 * The aggregation cron (Reports) must have run for these tables to be populated.
 *
 * Each table holds one row per (period bucket, store, product), so every query
 * here is constrained to the *current* bucket of the configured period —
 * summing the table unfiltered would yield lifetime totals and make the period
 * setting meaningless.
 */
class BestSellerProvider
{
    /** How many of a product's categories to consider when computing its badge. */
    private const MAX_CATEGORIES_PER_PRODUCT = 50;

    /**
     * Resolved aggregated table name per period, or null when it does not exist.
     *
     * @var array<string, string|null>
     */
    private array $tableCache = [];

    /**
     * @param ResourceConnection $resource
     * @param TimezoneInterface $timezone
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly TimezoneInterface $timezone,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Return the top best-selling product ids, optionally scoped to a category.
     *
     * Ranks are NOT assigned here: the caller still has to drop products the
     * storefront must not show (disabled, not visible, other website), so it
     * numbers the survivors itself to keep the ranking contiguous.
     *
     * @param int $storeId
     * @param int|null $categoryId
     * @param int $limit
     * @param string $period day|month|year
     * @return array<int, array{product_id: int, qty_ordered: float}> best first
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
                ->where('bs.period >= ?', $this->getPeriodStart($storeId, $period))
                ->group('bs.product_id')
                ->order('qty DESC')
                // Tiebreaker: without it, equal-selling products swap places
                // between two identical requests and ranks look unstable.
                ->order('bs.product_id ASC')
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
            $this->logger->error(
                'Magenx_BestSellerGraphQl: unable to read best sellers: ' . $e->getMessage(),
                ['exception' => $e]
            );

            return [];
        }

        $items = [];
        foreach ($rows as $row) {
            $items[] = [
                'product_id' => (int) $row['product_id'],
                'qty_ordered' => (float) $row['qty'],
            ];
        }

        return $items;
    }

    /**
     * Compute best-seller badges for many products at once.
     *
     * A product's rank in a category is 1 + the number of products in that
     * category that sold strictly more over the period; the product earns the
     * lowest (best) such rank that is at or above the max-rank threshold across
     * the categories it belongs to. Ties share a rank, as SQL RANK() would.
     *
     * The whole batch costs two queries regardless of how many products it
     * carries, which is what makes ProductInterface.best_seller safe on listing
     * grids rather than a per-card fan-out.
     *
     * @param int $storeId
     * @param int[] $productIds
     * @param int $maxRank
     * @param string $period day|month|year
     * @param int $rootCategoryId the store's root category, to keep ranking inside its tree
     * @return array<int, array{rank: int, category_id: int}> keyed by product id
     */
    public function getBadges(
        int $storeId,
        array $productIds,
        int $maxRank,
        string $period,
        int $rootCategoryId
    ): array {
        $productIds = array_values(array_unique(array_filter(array_map('intval', $productIds))));
        if (!$productIds || $maxRank < 1 || $rootCategoryId < 1) {
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
            $periodStart = $this->getPeriodStart($storeId, $period);

            // 1. The navigable categories each target product belongs to — one
            //    query for the whole batch. Levels 0/1 are the root and store
            //    root, not real merchandising categories, and the path filter
            //    keeps another store view's tree from ranking this store.
            $memberRows = $connection->fetchAll(
                $connection->select()
                    ->from(['ccp' => $ccpTable], ['product_id' => 'ccp.product_id', 'category_id' => 'ccp.category_id'])
                    ->join(['cce' => $cceTable], 'cce.entity_id = ccp.category_id', [])
                    ->where('ccp.product_id IN (?)', $productIds)
                    ->where('cce.level >= ?', 2)
                    ->where('cce.path LIKE ?', '1/' . $rootCategoryId . '/%')
            );

            $productCategories = [];
            $relevantCategories = [];
            foreach ($memberRows as $row) {
                $pid = (int) $row['product_id'];
                // Bound the work a single over-categorised product can create.
                if (count($productCategories[$pid] ?? []) >= self::MAX_CATEGORIES_PER_PRODUCT) {
                    continue;
                }
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
                        [
                            'category_id' => 'ccp.category_id',
                            'product_id' => 'ccp.product_id',
                            'qty' => 'SUM(bs.qty_ordered)',
                        ]
                    )
                    ->join(['bs' => $table], 'bs.product_id = ccp.product_id', [])
                    ->where('bs.store_id = ?', $storeId)
                    ->where('bs.period >= ?', $periodStart)
                    ->where('ccp.category_id IN (?)', array_keys($relevantCategories))
                    ->group('ccp.category_id')
                    ->group('ccp.product_id')
            );

            // Split the rows once: every quantity in a category feeds the ranking,
            // but only the batch's own products need a qty lookup. Ranking each
            // category once — rather than rescanning it per product — is what
            // keeps a wide grid off an O(products x categories x members) loop.
            $targetIds = array_flip($productIds);
            $categoryQuantities = [];
            $targetQty = [];
            foreach ($qtyRows as $row) {
                $cid = (int) $row['category_id'];
                $pid = (int) $row['product_id'];
                $qty = (float) $row['qty'];

                $categoryQuantities[$cid][] = $qty;
                if (isset($targetIds[$pid])) {
                    $targetQty[$cid][$pid] = $qty;
                }
            }
            foreach ($categoryQuantities as &$quantities) {
                rsort($quantities);
            }
            unset($quantities);

            // 3. Per target product, find its best qualifying rank across its
            //    categories.
            $badges = [];
            foreach ($productIds as $pid) {
                $best = null;
                foreach ($productCategories[$pid] ?? [] as $cid) {
                    $myQty = $targetQty[$cid][$pid] ?? 0.0;
                    if ($myQty <= 0) {
                        continue; // no sales for this product in the period
                    }

                    $rank = $this->countHigher($categoryQuantities[$cid] ?? [], $myQty) + 1;
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
            $this->logger->error(
                'Magenx_BestSellerGraphQl: unable to compute best-seller badges: ' . $e->getMessage(),
                ['exception' => $e]
            );

            return [];
        }
    }

    /**
     * How many quantities in a descending-sorted list are strictly greater than
     * the given one — i.e. how many products outsold it, so rank = result + 1.
     *
     * Binary search for the first element that is not greater; its index is the
     * count. Equal quantities are not counted, so tied products share a rank.
     *
     * @param float[] $sortedDesc quantities for one category, highest first
     * @param float $qty
     * @return int
     */
    private function countHigher(array $sortedDesc, float $qty): int
    {
        $low = 0;
        $high = count($sortedDesc);

        while ($low < $high) {
            $middle = intdiv($low + $high, 2);
            if ($sortedDesc[$middle] > $qty) {
                $low = $middle + 1;
            } else {
                $high = $middle;
            }
        }

        return $low;
    }

    /**
     * The first day of the period bucket the ranking should cover.
     *
     * Magento's aggregated rows are keyed by the start of their bucket, so
     * "day" is today's date, "month" the 1st of this month and "year" the 1st
     * of January. Resolved in the STORE's timezone, matching how the report
     * aggregation itself buckets orders.
     *
     * @param int $storeId
     * @param string $period
     * @return string Y-m-d
     */
    private function getPeriodStart(int $storeId, string $period): string
    {
        $date = $this->timezone->scopeDate($storeId);

        return match ($period) {
            Period::PERIOD_DAY => $date->format('Y-m-d'),
            Period::PERIOD_YEAR => $date->format('Y-01-01'),
            default => $date->format('Y-m-01'),
        };
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
     * Memoized: the existence check is a metadata round trip, and a single
     * GraphQL request can resolve both the query and a badge batch.
     *
     * @param string $period
     * @return string|null
     */
    private function resolveAggregatedTable(string $period): ?string
    {
        if (array_key_exists($period, $this->tableCache)) {
            return $this->tableCache[$period];
        }

        $suffix = match ($period) {
            Period::PERIOD_DAY => 'daily',
            Period::PERIOD_YEAR => 'yearly',
            default => 'monthly',
        };

        $table = $this->resource->getTableName('sales_bestsellers_aggregated_' . $suffix);

        return $this->tableCache[$period] = $this->resource->getConnection()->isTableExists($table) ? $table : null;
    }
}
