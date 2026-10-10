<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\BestSellerGraphQl\Test\Unit\Model\Resolver\Product;

use Magenx\BestSellerGraphQl\Model\BestSellerProvider;
use Magenx\BestSellerGraphQl\Model\Config;
use Magenx\BestSellerGraphQl\Model\Config\Source\Period;
use Magenx\BestSellerGraphQl\Model\Resolver\Product\BestSellerBadge;
use Magenx\BestSellerGraphQl\Model\StoreCategoryTree;
use Magento\Catalog\Api\Data\ProductInterface;
// The concrete model on purpose: url_key is not on CategoryInterface, so the
// resolver's getUrlKey() call relies on what the collection actually yields.
use Magento\Catalog\Model\Category;
use Magento\Catalog\Model\ResourceModel\Category\Collection as CategoryCollection;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory as CategoryCollectionFactory;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Query\Resolver\BatchRequestItemInterface;
use Magento\GraphQl\Model\Query\ContextExtensionInterface;
use Magento\GraphQl\Model\Query\ContextInterface;
use Magento\Store\Api\Data\StoreInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @see BestSellerBadge
 */
class BestSellerBadgeTest extends TestCase
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
     * @var CategoryCollectionFactory&MockObject
     */
    private $categoryCollectionFactory;

    /**
     * @var BestSellerBadge
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
        $this->categoryCollectionFactory = $this->createMock(CategoryCollectionFactory::class);

        $this->config->method('isBadgeEnabled')->willReturn(true);
        $this->config->method('getBadgeMaxRank')->willReturn(10);
        $this->config->method('getPeriod')->willReturn(Period::PERIOD_MONTH);
        $this->storeCategoryTree->method('getRootCategoryId')->willReturn(2);

        $this->resolver = new BestSellerBadge(
            $this->config,
            $this->provider,
            $this->storeCategoryTree,
            $this->categoryCollectionFactory
        );
    }

    /**
     * The whole batch must reach the provider in one call — that is what keeps
     * the field off a per-card fan-out.
     *
     * @return void
     */
    public function testWholeBatchIsResolvedInOneProviderCall(): void
    {
        $this->provider->expects($this->once())
            ->method('getBadges')
            ->with(1, [10, 20, 30], 10, Period::PERIOD_MONTH, 2)
            ->willReturn([]);

        $this->resolve([10, 20, 30]);
    }

    /**
     * @return void
     */
    public function testBadgeIsMappedBackToItsOwnRequest(): void
    {
        $this->provider->method('getBadges')->willReturn([
            20 => ['rank' => 3, 'category_id' => 17],
        ]);
        $this->stubCategory(17, 'Running Shoes', 'running-shoes');

        $requests = $this->requestsFor([10, 20]);
        $response = $this->resolver->resolve($this->context(), $this->createMock(Field::class), $requests);

        $this->assertNull($response->findResponseFor($requests[0]), 'Unranked product should have no badge');
        $this->assertSame(
            [
                'rank' => 3,
                'category_id' => 17,
                'category_name' => 'Running Shoes',
                'category_url_key' => 'running-shoes',
            ],
            $response->findResponseFor($requests[1])
        );
    }

    /**
     * Every badge category in the batch is named by one collection load, with
     * each distinct category asked for once.
     *
     * @return void
     */
    public function testBadgeCategoriesAreLoadedInOneQuery(): void
    {
        $this->provider->method('getBadges')->willReturn([
            10 => ['rank' => 1, 'category_id' => 17],
            20 => ['rank' => 2, 'category_id' => 17],
            30 => ['rank' => 1, 'category_id' => 18],
        ]);

        $collection = $this->categoryCollection([
            17 => ['Running Shoes', 'running-shoes'],
            18 => ['Trail', 'trail'],
        ]);
        $collection->expects($this->once())->method('setStoreId')->with(1);
        $collection->expects($this->once())
            ->method('addIdFilter')
            ->with($this->callback(static fn (array $ids): bool => array_values($ids) === [17, 18]));

        $this->categoryCollectionFactory->expects($this->once())
            ->method('create')
            ->willReturn($collection);

        $this->resolve([10, 20, 30]);
    }

    /**
     * No badge in the batch, no category query.
     *
     * @return void
     */
    public function testNoBadgesMeansNoCategoryQuery(): void
    {
        $this->provider->method('getBadges')->willReturn([]);
        $this->categoryCollectionFactory->expects($this->never())->method('create');

        $this->resolve([10, 20]);
    }

    /**
     * A disabled badge resolves to null for every product, and never asks the
     * provider to do the ranking work.
     *
     * @return void
     */
    public function testDisabledBadgeResolvesEveryRequestToNull(): void
    {
        $config = $this->createMock(Config::class);
        $config->method('isBadgeEnabled')->willReturn(false);

        $this->provider->expects($this->never())->method('getBadges');

        $resolver = new BestSellerBadge(
            $config,
            $this->provider,
            $this->storeCategoryTree,
            $this->categoryCollectionFactory
        );

        $requests = $this->requestsFor([10, 20]);
        $response = $resolver->resolve($this->context(), $this->createMock(Field::class), $requests);

        $this->assertNull($response->findResponseFor($requests[0]));
        $this->assertNull($response->findResponseFor($requests[1]));
    }

    /**
     * A request whose parent value carries no product model must not break the
     * batch or be mistaken for another product's badge.
     *
     * @return void
     */
    public function testRequestWithoutAProductModelResolvesToNull(): void
    {
        $this->provider->method('getBadges')->willReturn([
            10 => ['rank' => 1, 'category_id' => 17],
        ]);
        $this->stubCategory(17, 'Running Shoes', 'running-shoes');

        $modelless = $this->createMock(BatchRequestItemInterface::class);
        $modelless->method('getValue')->willReturn([]);

        $requests = $this->requestsFor([10]);
        $requests[] = $modelless;

        $response = $this->resolver->resolve($this->context(), $this->createMock(Field::class), $requests);

        $this->assertNotNull($response->findResponseFor($requests[0]));
        $this->assertNull($response->findResponseFor($modelless));
    }

    /**
     * A category deleted since the report ran still yields its rank, just
     * without a name.
     *
     * @return void
     */
    public function testMissingCategoryDegradesToNullNames(): void
    {
        $this->provider->method('getBadges')->willReturn([
            10 => ['rank' => 1, 'category_id' => 17],
        ]);
        $this->categoryCollectionFactory->method('create')->willReturn($this->categoryCollection([]));

        $requests = $this->requestsFor([10]);
        $response = $this->resolver->resolve($this->context(), $this->createMock(Field::class), $requests);

        $this->assertSame(
            ['rank' => 1, 'category_id' => 17, 'category_name' => null, 'category_url_key' => null],
            $response->findResponseFor($requests[0])
        );
    }

    /**
     * @param int[] $productIds
     * @return void
     */
    private function resolve(array $productIds): void
    {
        $this->resolver->resolve(
            $this->context(),
            $this->createMock(Field::class),
            $this->requestsFor($productIds)
        );
    }

    /**
     * @param int[] $productIds
     * @return BatchRequestItemInterface[]
     */
    private function requestsFor(array $productIds): array
    {
        $requests = [];
        foreach ($productIds as $productId) {
            $product = $this->createMock(ProductInterface::class);
            $product->method('getId')->willReturn($productId);

            $request = $this->createMock(BatchRequestItemInterface::class);
            $request->method('getValue')->willReturn(['model' => $product]);

            $requests[] = $request;
        }

        return $requests;
    }

    /**
     * @param int $categoryId
     * @param string $name
     * @param string $urlKey
     * @return void
     */
    private function stubCategory(int $categoryId, string $name, string $urlKey): void
    {
        $this->categoryCollectionFactory->method('create')
            ->willReturn($this->categoryCollection([$categoryId => [$name, $urlKey]]));
    }

    /**
     * A category collection that iterates the given categories.
     *
     * @param array<int, array{0: string, 1: string}> $categories id => [name, url_key]
     * @return CategoryCollection&MockObject
     */
    private function categoryCollection(array $categories)
    {
        $models = [];
        foreach ($categories as $id => [$name, $urlKey]) {
            $category = $this->createMock(Category::class);
            $category->method('getId')->willReturn($id);
            $category->method('getName')->willReturn($name);
            $category->method('getUrlKey')->willReturn($urlKey);
            $models[] = $category;
        }

        $collection = $this->createMock(CategoryCollection::class);
        $collection->method('getIterator')->willReturn(new \ArrayIterator($models));

        return $collection;
    }

    /**
     * @return ContextInterface&MockObject
     */
    private function context()
    {
        $store = $this->createMock(StoreInterface::class);
        $store->method('getId')->willReturn(1);

        $extensionAttributes = $this->createMock(ContextExtensionInterface::class);
        $extensionAttributes->method('getStore')->willReturn($store);

        $context = $this->createMock(ContextInterface::class);
        $context->method('getExtensionAttributes')->willReturn($extensionAttributes);

        return $context;
    }
}
