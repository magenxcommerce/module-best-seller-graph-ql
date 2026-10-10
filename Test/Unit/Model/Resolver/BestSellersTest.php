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
use Magento\Catalog\Model\Product\Visibility;
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
     * @var Visibility&MockObject
     */
    private $visibility;

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
        $this->visibility = $this->createMock(Visibility::class);

        $this->config->method('isEnabled')->willReturn(true);
        $this->config->method('getCount')->willReturn(20);
        $this->config->method('getPeriod')->willReturn(Period::PERIOD_MONTH);
        $this->visibility->method('getVisibleInSiteIds')->willReturn([1, 2, 3]);

        $this->resolver = new BestSellers(
            $this->config,
            $this->provider,
            $this->storeCategoryTree,
            $this->collectionFactory,
            $this->visibility
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
            $this->collectionFactory,
            $this->visibility
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
        $this->assertArrayNotHasKey(
            'qty_ordered',
            $result['items'][0],
            'Sales volume must not be published by a public query'
        );

        $this->assertSame(2, $result['items'][1]['rank']);
        $this->assertSame(30, $result['items'][1]['product']['entity_id']);
    }

    /**
     * Candidates are over-fetched to survive filtering, but only the products
     * that make the page are hydrated with their attribute values.
     *
     * @return void
     */
    public function testOnlyThePagesProductsAreHydrated(): void
    {
        $this->provider->method('getBestSellers')->willReturn([
            ['product_id' => 10, 'qty_ordered' => 90.0],
            ['product_id' => 20, 'qty_ordered' => 80.0],
            ['product_id' => 30, 'qty_ordered' => 70.0],
            ['product_id' => 40, 'qty_ordered' => 60.0],
            ['product_id' => 50, 'qty_ordered' => 50.0],
        ]);

        $hydrated = $this->stubLoadedProducts([20, 30, 40, 50]);

        $result = $this->invoke($this->resolver, ['pageSize' => 2]);

        $this->assertSame([20, 30], $hydrated->getArrayCopy());
        $this->assertSame([1, 2], array_column($result['items'], 'rank'));
    }

    /**
     * When no candidate is visible there is nothing to hydrate.
     *
     * @return void
     */
    public function testNoVisibleCandidatesSkipsHydration(): void
    {
        $this->provider->method('getBestSellers')->willReturn([
            ['product_id' => 10, 'qty_ordered' => 90.0],
        ]);

        $filter = $this->createMock(Collection::class);
        $filter->method('getAllIds')->willReturn([]);
        $this->collectionFactory->expects($this->once())->method('create')->willReturn($filter);

        $this->assertSame(['items' => [], 'total_count' => 0], $this->invoke($this->resolver));
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
     * Make the collection factory model the two reads: the id-only visibility
     * filter (which reports `$visibleIds`), then the hydration of whatever ids
     * the resolver asks for. The ids it hydrates are returned by reference so
     * a test can assert nothing beyond the page was loaded.
     *
     * @param int[] $visibleIds
     * @return \ArrayObject<int, int> the ids passed to the hydrating collection
     */
    private function stubLoadedProducts(array $visibleIds): \ArrayObject
    {
        $fluent = ['setStoreId', 'addAttributeToSelect', 'addWebsiteFilter', 'addAttributeToFilter'];

        $filter = $this->createMock(Collection::class);
        foreach ([...$fluent, 'addIdFilter'] as $method) {
            $filter->method($method)->willReturnSelf();
        }
        // Magento hands ids back as strings.
        $filter->method('getAllIds')->willReturn(array_map('strval', $visibleIds));
        $filter->expects($this->never())->method('getIterator');

        $hydrated = new \ArrayObject();
        $loader = $this->createMock(Collection::class);
        foreach ($fluent as $method) {
            $loader->method($method)->willReturnSelf();
        }
        $loader->method('addIdFilter')->willReturnCallback(
            function (array $ids) use ($loader, $hydrated) {
                $hydrated->exchangeArray(array_values($ids));
                return $loader;
            }
        );
        $loader->method('getIterator')->willReturnCallback(function () use ($hydrated) {
            $products = [];
            foreach ($hydrated as $productId) {
                $product = $this->createMock(Product::class);
                $product->method('getId')->willReturn($productId);
                $product->method('getData')->willReturn(['entity_id' => $productId]);
                $products[] = $product;
            }
            return new \ArrayIterator($products);
        });

        $this->collectionFactory->method('create')->willReturnOnConsecutiveCalls($filter, $loader);

        return $hydrated;
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
