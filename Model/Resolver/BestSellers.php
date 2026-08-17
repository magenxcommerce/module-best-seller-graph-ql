<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\BestSellerGraphQl\Model\Resolver;

use Magenx\BestSellerGraphQl\Model\BestSellerProvider;
use Magenx\BestSellerGraphQl\Model\Config;
use Magenx\BestSellerGraphQl\Model\StoreCategoryTree;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Exception\GraphQlInputException;
use Magento\Framework\GraphQl\Query\ResolverInterface;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Magento\Store\Api\Data\StoreInterface;

/**
 * Resolves the `bestSellers` query.
 *
 * The report tables rank raw product ids and know nothing about the catalog, so
 * the ranking is filtered here against what the storefront is actually allowed
 * to show — enabled, visible, assigned to this website. More candidates than
 * asked for are pulled so those removals still fill the page, and ranks are
 * assigned only afterwards, which keeps them contiguous (1..N) instead of
 * leaving holes where a hidden product used to sit.
 *
 * The surviving products are loaded once, as a single collection, and handed to
 * {@see BestSellerProduct} on the item — so a wide list costs one product query,
 * not one per row.
 *
 * Returns an empty list (rather than erroring) when the feature is disabled or
 * the requested category is not this store's to rank, so the storefront degrades
 * gracefully.
 */
class BestSellers implements ResolverInterface
{
    /**
     * Multiple of the requested page size to pull before filtering, so a page
     * can still be filled when some ranked products are not publicly visible.
     */
    private const CANDIDATE_MULTIPLIER = 3;

    /** Hard ceiling on candidates, so a large configured count stays bounded. */
    private const MAX_CANDIDATES = 300;

    /**
     * @param Config $config
     * @param BestSellerProvider $provider
     * @param StoreCategoryTree $storeCategoryTree
     * @param CollectionFactory $productCollectionFactory
     * @param Visibility $visibility
     */
    public function __construct(
        private readonly Config $config,
        private readonly BestSellerProvider $provider,
        private readonly StoreCategoryTree $storeCategoryTree,
        private readonly CollectionFactory $productCollectionFactory,
        private readonly Visibility $visibility
    ) {
    }

    /**
     * @inheritDoc
     */
    public function resolve(Field $field, $context, ResolveInfo $info, ?array $value = null, ?array $args = null)
    {
        $empty = ['items' => [], 'total_count' => 0];

        $store = $context->getExtensionAttributes()->getStore();
        $storeId = (int) $store->getId();

        if (!$this->config->isEnabled($storeId)) {
            return $empty;
        }

        $max = $this->config->getCount($storeId);
        $requested = isset($args['pageSize']) ? (int) $args['pageSize'] : $max;
        $limit = max(1, min($requested, $max));

        $categoryId = null;
        if (isset($args['category_id'])) {
            $categoryId = (int) $args['category_id'];
            if ($categoryId <= 0) {
                throw new GraphQlInputException(__('"category_id" must be a positive integer.'));
            }
            // Unknown, disabled, or another store's category: nothing to rank.
            if (!$this->storeCategoryTree->isInStoreTree($categoryId, $store)) {
                return $empty;
            }
        }

        $candidates = $this->provider->getBestSellers(
            $storeId,
            $categoryId,
            min($limit * self::CANDIDATE_MULTIPLIER, self::MAX_CANDIDATES),
            $this->config->getPeriod($storeId)
        );

        if (!$candidates) {
            return $empty;
        }

        $products = $this->loadVisibleProducts(array_column($candidates, 'product_id'), $store);

        $items = [];
        $rank = 0;
        foreach ($candidates as $candidate) {
            $product = $products[$candidate['product_id']] ?? null;
            if ($product === null) {
                continue; // disabled, not visible, or not in this website
            }

            $productData = $product->getData();
            // The contract the CatalogGraphQl ProductInterface field resolvers
            // (name, price_range, image, …) read from.
            $productData['model'] = $product;

            $items[] = [
                'rank' => ++$rank,
                'qty_ordered' => $candidate['qty_ordered'],
                // Consumed by the BestSellerProduct field resolver.
                'product' => $productData,
            ];

            if ($rank === $limit) {
                break;
            }
        }

        return [
            'items' => $items,
            'total_count' => count($items),
        ];
    }

    /**
     * Load the ranked products that the storefront may show, keyed by id.
     *
     * Filtering on the attributes directly (rather than via the collection's
     * visibility limitation, which joins the category index) means a product
     * that sells well but sits in no category is still returned.
     *
     * @param int[] $productIds
     * @param StoreInterface $store
     * @return array<int, \Magento\Catalog\Api\Data\ProductInterface>
     */
    private function loadVisibleProducts(array $productIds, StoreInterface $store): array
    {
        $collection = $this->productCollectionFactory->create();
        $collection->setStoreId((int) $store->getId());
        $collection->addAttributeToSelect('*');
        $collection->addIdFilter($productIds);
        $collection->addWebsiteFilter((int) $store->getWebsiteId());
        $collection->addAttributeToFilter('status', ['eq' => Status::STATUS_ENABLED]);
        $collection->addAttributeToFilter('visibility', ['in' => $this->visibility->getVisibleInSiteIds()]);

        $products = [];
        foreach ($collection as $product) {
            $products[(int) $product->getId()] = $product;
        }

        return $products;
    }
}
