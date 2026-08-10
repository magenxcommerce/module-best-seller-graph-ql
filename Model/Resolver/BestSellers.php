<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\BestSellerGraphQl\Model\Resolver;

use Magenx\BestSellerGraphQl\Model\BestSellerProvider;
use Magenx\BestSellerGraphQl\Model\Config;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Query\ResolverInterface;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;

/**
 * Resolves the `bestSellers` query.
 *
 * Returns ranked product references; the `product` sub-field is resolved
 * lazily by {@see BestSellerProduct} so a grid only pays for the fields it asks
 * for. Returns an empty list (rather than erroring) when the feature is
 * disabled, so the storefront degrades gracefully.
 */
class BestSellers implements ResolverInterface
{
    /**
     * @param Config $config
     * @param BestSellerProvider $provider
     */
    public function __construct(
        private readonly Config $config,
        private readonly BestSellerProvider $provider
    ) {
    }

    /**
     * @inheritDoc
     */
    public function resolve(Field $field, $context, ResolveInfo $info, ?array $value = null, ?array $args = null)
    {
        $store = $context->getExtensionAttributes()->getStore();
        $storeId = (int) $store->getId();

        if (!$this->config->isEnabled($storeId)) {
            return ['items' => [], 'total_count' => 0];
        }

        $max = $this->config->getCount($storeId);
        $requested = isset($args['pageSize']) ? (int) $args['pageSize'] : $max;
        $limit = max(1, min($requested, $max));

        $categoryId = isset($args['category_id']) ? (int) $args['category_id'] : null;

        $rows = $this->provider->getBestSellers(
            $storeId,
            $categoryId,
            $limit,
            $this->config->getPeriod($storeId)
        );

        $items = [];
        foreach ($rows as $row) {
            $items[] = [
                'rank' => $row['rank'],
                'qty_ordered' => $row['qty_ordered'],
                // Consumed by the BestSellerProduct field resolver.
                'product_id' => $row['product_id'],
                'store_id' => $storeId,
            ];
        }

        return [
            'items' => $items,
            'total_count' => count($items),
        ];
    }
}
