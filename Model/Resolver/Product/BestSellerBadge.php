<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\BestSellerGraphQl\Model\Resolver\Product;

use Magenx\BestSellerGraphQl\Model\BestSellerProvider;
use Magenx\BestSellerGraphQl\Model\Config;
use Magenx\BestSellerGraphQl\Model\StoreCategoryTree;
use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Framework\Exception\NoSuchEntityException;
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
 * of queries (a pair per grid), then maps the answers back. That is what makes
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
     * @param CategoryRepositoryInterface $categoryRepository
     */
    public function __construct(
        private readonly Config $config,
        private readonly BestSellerProvider $provider,
        private readonly StoreCategoryTree $storeCategoryTree,
        private readonly CategoryRepositoryInterface $categoryRepository
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

        // Category name/url_key lookups are cached per distinct category so a
        // grid where several products rank in the same category loads it once.
        $categoryCache = [];

        foreach ($requests as $key => $request) {
            $productId = $productIds[$key] ?? 0;

            if (!isset($badges[$productId])) {
                $response->addResponse($request, null);
                continue;
            }

            $badge = $badges[$productId];
            $categoryId = (int) $badge['category_id'];
            if (!array_key_exists($categoryId, $categoryCache)) {
                $categoryCache[$categoryId] = $this->loadCategory($categoryId, $storeId);
            }
            [$categoryName, $categoryUrlKey] = $categoryCache[$categoryId];

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
     * Resolve a category's display name + url_key, degrading to nulls when the
     * category has been removed (still surface the rank without its name).
     *
     * @param int $categoryId
     * @param int $storeId
     * @return array{0: string|null, 1: string|null}
     */
    private function loadCategory(int $categoryId, int $storeId): array
    {
        try {
            $category = $this->categoryRepository->get($categoryId, $storeId);
            return [$category->getName(), $category->getUrlKey()];
        } catch (NoSuchEntityException $e) {
            return [null, null];
        }
    }
}
