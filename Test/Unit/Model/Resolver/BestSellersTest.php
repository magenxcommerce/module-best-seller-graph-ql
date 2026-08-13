<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\BestSellerGraphQl\Test\Unit\Model\Resolver;

use Magenx\BestSellerGraphQl\Model\BestSellerProvider;
use Magenx\BestSellerGraphQl\Model\Config;
use Magenx\BestSellerGraphQl\Model\Config\Source\Period;
use Magenx\BestSellerGraphQl\Model\Resolver\BestSellers;
use Magenx\BestSellerGraphQl\Model\StoreCategoryTree;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\ResourceModel\Product\Collection;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Exception\GraphQlInputException;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Magento\GraphQl\Model\Query\ContextExtensionInterface;
use Magento\GraphQl\Model\Query\ContextInterface;
use Magento\Store\Api\Data\StoreInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @see BestSellers
 */
class BestSellersTest extends TestCase
{
    /**
     * @var Config&MockObject
     */
    private $config;

    /**
     * @var BestSellerProvider&MockObject
     */
    private $provider;

    /**
     * @var StoreCategoryTree&MockObject
     */
    private $storeCategoryTree;

    /**
     * @var CollectionFactory&MockObject
     */
    private $collectionFactory;

    /**
     * @var BestSellers
     */
    private $resolver;

    /**
     * @inheritDoc
     */
    protected function setUp(): void
    {
        $this->config = $this->createMock(Config::class);
        $this->provider = $this->createMock(BestSellerProvider::class);
        $this->storeCategoryTree = $this->createMock(StoreCategoryTree::class);
        $this->collectionFactory = $this->createMock(CollectionFactory::class);

        $this->config->method('isEnabled')->willReturn(true);
        $this->config->method('getCount')->willReturn(20);
        $this->config->method('getPeriod')->willReturn(Period::PERIOD_MONTH);

        $this->resolver = new BestSellers(
            $this->config,
            $this->provider,
            $this->storeCategoryTree,
            $this->collectionFactory
        );
    }

    /**
     * A disabled feature answers with the empty shape, never an error, so the
     * storefront can keep the query in its document unconditionally.
     *
     * @return void
     */
    public function testDisabledFeatureReturnsEmptyResult(): void
    {
        $config = $this->createMock(Config::class);
        $config->method('isEnabled')->willReturn(false);

        $this->provider->expects($this->never())->method('getBestSellers');

        $resolver = new BestSellers(
            $config,
            $this->provider,
            $this->storeCategoryTree,
            $this->collectionFactory
        );

        $this->assertSame(['items' => [], 'total_count' => 0], $this->invoke($resolver));
    }

    /**
     * @return void
     */
    public function testNonPositiveCategoryIdIsRejected(): void
    {
        $this->expectException(GraphQlInputException::class);

        $this->invoke($this->resolver, ['category_id' => 0]);
    }

    /**
     * A category belonging to another store is answered with an empty list
     * rather than its ranking.
     *
     * @return void
     */
    public function testCategoryOutsideTheStoreTreeReturnsEmptyResult(): void
    {
        $this->storeCategoryTree->method('isInStoreTree')->willReturn(false);
        $this->provider->expects($this->never())->method('getBestSellers');

        $this->assertSame(
            ['items' => [], 'total_count' => 0],
            $this->invoke($this->resolver, ['category_id' => 77])
        );
    }

    /**
     * pageSize is clamped to the configured count, and more candidates than
     * asked for are pulled so filtering can still fill the page.
     *
     * @return void
     */
    public function testPageSizeIsClampedAndOverFetched(): void
    {
        $this->provider->expects($this->once())
            ->method('getBestSellers')
            // 999 clamps to the configured 20, then over-fetches 3x.
            ->with(1, null, 60, Period::PERIOD_MONTH)
            ->willReturn([]);

        $this->invoke($this->resolver, ['pageSize' => 999]);
    }

    /**
     * @return void
     */
    public function testPageSizeBelowOneIsRaisedToOne(): void
    {
        $this->provider->expects($this->once())
            ->method('getBestSellers')
            ->with(1, null, 3, Period::PERIOD_MONTH)
            ->willReturn([]);

        $this->invoke($this->resolver, ['pageSize' => -5]);
    }

    /**
     * The important guarantee of the filtering rewrite: a ranked product the
     * storefront may not show is dropped without leaving a hole in the ranks,
     * and the page is still truncated to the requested size.
     *
     * @return void
     */
    public function testHiddenProductsAreDroppedAndRanksStayContiguous(): void
    {
        $this->provider->method('getBestSellers')->willReturn([
            ['product_id' => 10, 'qty_ordered' => 90.0],
            ['product_id' => 20, 'qty_ordered' => 80.0],
            ['product_id' => 30, 'qty_ordered' => 70.0],
            ['product_id' => 40, 'qty_ordered' => 60.0],
        ]);

        // Product 10 is hidden (disabled / not visible / other website).
        $this->stubLoadedProducts([20, 30, 40]);

        $result = $this->invoke($this->resolver, ['pageSize' => 2]);

        $this->assertSame(2, $result['total_count']);
        $this->assertCount(2, $result['items']);

        $this->assertSame(1, $result['items'][0]['rank']);
        $this->assertSame(20, $result['items'][0]['product']['entity_id']);
        $this->assertSame(80.0, $result['items'][0]['qty_ordered']);

        $this->assertSame(2, $result['items'][1]['rank']);
        $this->assertSame(30, $result['items'][1]['product']['entity_id']);
    }

    /**
     * The loaded product model is attached under `model`, the contract the
     * CatalogGraphQl field resolvers read from.
     *
     * @return void
     */
    public function testProductModelIsAttachedForDownstreamResolvers(): void
    {
        $this->provider->method('getBestSellers')->willReturn([
            ['product_id' => 20, 'qty_ordered' => 80.0],
        ]);
        $this->stubLoadedProducts([20]);

        $result = $this->invoke($this->resolver, ['pageSize' => 5]);

        $this->assertInstanceOf(Product::class, $result['items'][0]['product']['model']);
    }

    /**
     * Make the collection factory hand back a collection carrying these ids.
     *
     * @param int[] $productIds
     * @return void
     */
    private function stubLoadedProducts(array $productIds): void
    {
        $products = [];
        foreach ($productIds as $productId) {
            $product = $this->createMock(Product::class);
            $product->method('getId')->willReturn($productId);
            $product->method('getData')->willReturn(['entity_id' => $productId]);
            $products[] = $product;
        }

        $fluent = ['setStoreId', 'addAttributeToSelect', 'addIdFilter', 'addWebsiteFilter', 'addAttributeToFilter'];

        $collection = $this->createMock(Collection::class);
        foreach ($fluent as $method) {
            $collection->method($method)->willReturnSelf();
        }
        $collection->method('getIterator')->willReturn(new \ArrayIterator($products));

        $this->collectionFactory->method('create')->willReturn($collection);
    }

    /**
     * @param BestSellers $resolver
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    private function invoke(BestSellers $resolver, array $args = []): array
    {
        $store = $this->createMock(StoreInterface::class);
        $store->method('getId')->willReturn(1);
        $store->method('getWebsiteId')->willReturn(1);

        $extensionAttributes = $this->createMock(ContextExtensionInterface::class);
        $extensionAttributes->method('getStore')->willReturn($store);

        $context = $this->createMock(ContextInterface::class);
        $context->method('getExtensionAttributes')->willReturn($extensionAttributes);

        return $resolver->resolve(
            $this->createMock(Field::class),
            $context,
            $this->createMock(ResolveInfo::class),
            null,
            $args
        );
    }
}
