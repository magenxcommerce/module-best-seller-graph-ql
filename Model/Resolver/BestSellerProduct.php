<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\BestSellerGraphQl\Model\Resolver;

use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Query\ResolverInterface;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;

/**
 * Resolves the `product` field on a BestSellerItem.
 *
 * Deliberately does no loading of its own. {@see BestSellers} already has to
 * load every ranked product to decide whether the storefront may show it, so it
 * attaches the finished product data — including the `model` key the
 * CatalogGraphQl ProductInterface field resolvers (name, price_range, image, …)
 * read from — to the item. Loading here instead would mean one product query per
 * ranked row, the fan-out this module avoids everywhere else.
 */
class BestSellerProduct implements ResolverInterface
{
    /**
     * @inheritDoc
     */
    public function resolve(Field $field, $context, ResolveInfo $info, ?array $value = null, ?array $args = null)
    {
        return $value['product'] ?? null;
    }
}
