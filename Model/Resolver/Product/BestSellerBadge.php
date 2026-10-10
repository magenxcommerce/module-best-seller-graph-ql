<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\BestSellerGraphQl\Model\Resolver\Product;

use Magenx\BestSellerGraphQl\Model\BestSellerProvider;
use Magenx\BestSellerGraphQl\Model\Config;
use Magenx\BestSellerGraphQl\Model\StoreCategoryTree;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory as CategoryCollectionFactory;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Query\Resolver\BatchRequestItemInterface;
use Magento\Framework\GraphQl\Query\Resolver\BatchResolverInterface;
use Magento\Framework\GraphQl\Query\Resolver\BatchResponse;
use Magento\Framework\GraphQl\Query\Resolver\ContextInterface;

/**
 * Resolves ProductInterface.best_seller — the Amazon-style per-category badge.
 *
 * A BATCH resolver on purpose: Magento hands it every product request for a
 * grid branch in a single call, so it collects all product ids and computes
 * their badges with {@see BestSellerProvider::getBadges()} in a bounded number
 * of queries (a handful per grid), then maps the answers back. That is what makes
 * this field safe on listing grids (PRODUCT_CARD_FIELDS) — the same
 * anti-fan-out mechanism the price_history resolver uses. Resolving one product
 * at a time here would put a handful of ranking queries behind every card, so
 * this MUST stay a BatchResolverInterface.
 *
 * Returns null for a product when the badge is disabled or the product does not
 * rank within the configured threshold in any of its categories, so it never
 * breaks a product query.
 */
class BestSellerBadge implements BatchResolverInterface
{
    /**
     * @param Config $config
     * @param BestSellerProvider $provider
     * @param StoreCategoryTree $storeCategoryTree
     * @param CategoryCollectionFactory $categoryCollectionFactory
     */
    public function __construct(
        private readonly Config $config,
        private readonly BestSellerProvider $provider,
        private readonly StoreCategoryTree $storeCategoryTree,
        private readonly CategoryCollectionFactory $categoryCollectionFactory
    ) {
    }

    /**
     * @param ContextInterface $context
     * @param Field $field
     * @param BatchRequestItemInterface[] $requests
     * @return BatchResponse
     */
    public function resolve(ContextInterface $context, Field $field, array $requests): BatchResponse
    {
        $response = new BatchResponse();

        $store = $context->getExtensionAttributes()->getStore();
        $storeId = (int) $store->getId();
        $enabled = $this->config->isBadgeEnabled($storeId);

        // Resolve each request's product id once, up front: the mapping below
        // needs it a second time and digging it back out of the request value
        // is not free.
        $productIds = [];
        foreach ($requests as $key => $request) {
            $product = $this->productOf($request);
            $productIds[$key] = $product !== null ? (int) $product->getId() : 0;
        }

        $ids = array_values(array_filter($productIds));

        $badges = ($enabled && $ids)
            ? $this->provider->getBadges(
                $storeId,
                $ids,
                $this->config->getBadgeMaxRank($storeId),
                $this->config->getPeriod($storeId),
                $this->storeCategoryTree->getRootCategoryId($store)
            )
            : [];

        // Names and url keys for every badge category in the batch, in one
        // query — not a repository load per distinct category.
        $categories = $badges
            ? $this->loadCategories(array_unique(array_column($badges, 'category_id')), $storeId)
            : [];

        foreach ($requests as $key => $request) {
            $productId = $productIds[$key] ?? 0;

            if (!isset($badges[$productId])) {
                $response->addResponse($request, null);
                continue;
            }

            $badge = $badges[$productId];
            $categoryId = (int) $badge['category_id'];
            [$categoryName, $categoryUrlKey] = $categories[$categoryId] ?? [null, null];

            $response->addResponse($request, [
                'rank' => $badge['rank'],
                'category_id' => $categoryId,
                'category_name' => $categoryName,
                'category_url_key' => $categoryUrlKey,
            ]);
        }

        return $response;
    }

    /**
     * The product model carried on a batch request's parent value, or null.
     *
     * @param BatchRequestItemInterface $request
     * @return ProductInterface|null
     */
    private function productOf(BatchRequestItemInterface $request): ?ProductInterface
    {
        $value = $request->getValue();

        return isset($value['model']) && $value['model'] instanceof ProductInterface
            ? $value['model']
            : null;
    }

    /**
     * Display name + url_key per category id, store-scoped. A category removed
     * since the ranking was read is simply absent, and its badge degrades to
     * null names (the rank still surfaces).
     *
     * @param int[] $categoryIds
     * @param int $storeId
     * @return array<int, array{0: string|null, 1: string|null}>
     */
    private function loadCategories(array $categoryIds, int $storeId): array
    {
        $collection = $this->categoryCollectionFactory->create();
        $collection->setStoreId($storeId);
        $collection->addIdFilter($categoryIds);
        $collection->addAttributeToSelect(['name', 'url_key']);

        $categories = [];
        foreach ($collection as $category) {
            $categories[(int) $category->getId()] = [$category->getName(), $category->getUrlKey()];
        }

        return $categories;
    }
}
