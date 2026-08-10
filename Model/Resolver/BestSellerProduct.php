<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\BestSellerGraphQl\Model\Resolver;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Exception\GraphQlInputException;
use Magento\Framework\GraphQl\Query\ResolverInterface;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;

/**
 * Resolves the `product` field on a BestSellerItem.
 *
 * Returns the product data array with the loaded product model attached under
 * `model`, the contract the CatalogGraphQl ProductInterface field resolvers
 * (name, price_range, image, …) read from. Mirrors Magento_WishlistGraphQl's /
 * Magenx_ProductAlertGraphQl's product resolver.
 */
class BestSellerProduct implements ResolverInterface
{
    /**
     * @param ProductRepositoryInterface $productRepository
     */
    public function __construct(
        private readonly ProductRepositoryInterface $productRepository
    ) {
    }

    /**
     * @inheritDoc
     */
    public function resolve(Field $field, $context, ResolveInfo $info, ?array $value = null, ?array $args = null)
    {
        if (!isset($value['product_id'])) {
            throw new GraphQlInputException(__('"product_id" value should be specified.'));
        }

        $storeId = isset($value['store_id'])
            ? (int) $value['store_id']
            : (int) $context->getExtensionAttributes()->getStore()->getId();

        try {
            $product = $this->productRepository->getById((int) $value['product_id'], false, $storeId);
        } catch (NoSuchEntityException $e) {
            // Product was deleted from the catalog; the ranking line can't render it.
            return null;
        }

        $productData = $product->getData();
        $productData['model'] = $product;

        return $productData;
    }
}
