<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\BestSellerGraphQl\Test\Unit\Model;

use Magenx\BestSellerGraphQl\Model\BestSellerProvider;
use Magenx\BestSellerGraphQl\Model\Config\Source\Period;
use Magento\Catalog\Model\ResourceModel\Category\Collection as CategoryCollection;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory as CategoryCollectionFactory;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Covers the provider's pure logic — the ranking maths and the period bucket
 * boundary. Both are private because nothing outside the class should call
 * them, but both decide what a shopper sees, so they are exercised directly
 * rather than through a mocked-out SQL chain that would assert little.
 *
 * @see BestSellerProvider
 */
class BestSellerProviderTest extends TestCase
{
    /**
     * @var TimezoneInterface&MockObject
     */
    private $timezone;

    /**
     * @var BestSellerProvider
     */
    private $provider;

    /**
     * @inheritDoc
     */
    protected function setUp(): void
    {
        $this->timezone = $this->createMock(TimezoneInterface::class);
        $this->provider = new BestSellerProvider(
            $this->createMock(ResourceConnection::class),
            $this->timezone,
            $this->createMock(LoggerInterface::class),
            $this->createMock(CategoryCollectionFactory::class)
        );
    }

    /**
     * The rank of a product is 1 + however many outsold it.
     *
     * @return void
     */
    public function testCountHigherRanksAgainstSortedQuantities(): void
    {
        // One category's quantities, highest first, as getBadges() prepares them.
        $quantities = [100.0, 80.0, 80.0, 50.0, 10.0];

        $cases = [
            // [own qty, expected number of products that sold strictly more]
            [100.0, 0],
            [90.0, 1],
            [80.0, 1],
            [50.0, 3],
            [10.0, 4],
            [1.0, 5],
        ];

        foreach ($cases as [$qty, $expected]) {
            $this->assertSame(
                $expected,
                $this->countHigher($quantities, $qty),
                sprintf('qty %s should be outsold by %d product(s)', var_export($qty, true), $expected)
            );
        }
    }

    /**
     * Tied products share a rank rather than being ordered arbitrarily, which
     * is what SQL RANK() would do and what the badge copy implies.
     *
     * @return void
     */
    public function testCountHigherTreatsTiesAsEqual(): void
    {
        $quantities = [40.0, 40.0, 40.0];

        // All three are rank 1: none of them outsold another.
        $this->assertSame(0, $this->countHigher($quantities, 40.0));
    }

    /**
     * A category nobody has sold in must not crash the ranking.
     *
     * @return void
     */
    public function testCountHigherHandlesEmptyCategory(): void
    {
        $this->assertSame(0, $this->countHigher([], 5.0));
    }

    /**
     * The binary search must agree with a naive scan for every position, since
     * an off-by-one here silently shifts every badge on the site.
     *
     * @return void
     */
    public function testCountHigherMatchesNaiveCount(): void
    {
        $quantities = [];
        for ($i = 0; $i < 50; $i++) {
            $quantities[] = (float) (100 - $i * 2);
        }
        // Introduce ties so the equal-value boundary is covered too.
        $quantities[10] = $quantities[9];
        rsort($quantities);

        foreach ($quantities as $qty) {
            $naive = count(array_filter($quantities, static fn (float $other) => $other > $qty));

            $this->assertSame(
                $naive,
                $this->countHigher($quantities, $qty),
                sprintf('Binary search disagreed with a naive count at qty %s', var_export($qty, true))
            );
        }
    }

    /**
     * Each period resolves to the first day of the bucket it names, so the
     * query sums the current day / month / year rather than the whole table.
     *
     * @return void
     */
    public function testPeriodStartIsTheStartOfTheCurrentBucket(): void
    {
        $this->timezone->method('scopeDate')->willReturn(new \DateTime('2026-08-13 15:04:05'));

        $this->assertSame('2026-08-13', $this->periodStart(Period::PERIOD_DAY));
        $this->assertSame('2026-08-01', $this->periodStart(Period::PERIOD_MONTH));
        $this->assertSame('2026-01-01', $this->periodStart(Period::PERIOD_YEAR));
    }

    /**
     * An unrecognised period must not drop the bucket filter altogether, which
     * would quietly restore lifetime totals.
     *
     * @return void
     */
    public function testUnknownPeriodStillBucketsByMonth(): void
    {
        $this->timezone->method('scopeDate')->willReturn(new \DateTime('2026-08-13 15:04:05'));

        $this->assertSame('2026-08-01', $this->periodStart('weekly'));
    }

    /**
     * The bucket boundary follows the store's timezone, matching how the
     * Reports aggregation itself buckets orders.
     *
     * @return void
     */
    public function testPeriodStartUsesTheStoreScope(): void
    {
        $this->timezone->expects($this->once())
            ->method('scopeDate')
            ->with(3)
            ->willReturn(new \DateTime('2026-08-13 15:04:05'));

        $this->periodStart(Period::PERIOD_DAY, 3);
    }

    /**
     * A disabled category must not name a badge (its name is not public and
     * its URL 404s): the product falls back to its best active category.
     *
     * @return void
     */
    public function testBadgeSkipsDisabledCategories(): void
    {
        $this->timezone->method('scopeDate')->willReturn(new \DateTime('2026-08-13'));

        $select = $this->createMock(Select::class);
        foreach (['from', 'join', 'where', 'group'] as $method) {
            $select->method($method)->willReturnSelf();
        }

        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        $connection->method('isTableExists')->willReturn(true);
        $connection->method('fetchAll')->willReturnOnConsecutiveCalls(
            // Product 10 sits in category 5 (disabled) and 6 (active).
            [
                ['product_id' => 10, 'category_id' => 5],
                ['product_id' => 10, 'category_id' => 6],
            ],
            // Product 10 would rank #1 in disabled category 5, but only #2 in
            // category 6, where product 99 outsold it.
            [
                ['category_id' => 5, 'product_id' => 10, 'qty' => 4],
                ['category_id' => 6, 'product_id' => 10, 'qty' => 4],
                ['category_id' => 6, 'product_id' => 99, 'qty' => 9],
            ]
        );

        $resource = $this->createMock(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnArgument(0);

        $categories = $this->createMock(CategoryCollection::class);
        $categories->expects($this->once())->method('setStoreId')->with(1);
        $categories->expects($this->once())->method('addIdFilter')->with([5, 6]);
        $categories->expects($this->once())->method('addAttributeToFilter')->with('is_active', 1);
        $categories->method('getAllIds')->willReturn(['6']);

        $categoryCollectionFactory = $this->createMock(CategoryCollectionFactory::class);
        $categoryCollectionFactory->method('create')->willReturn($categories);

        $provider = new BestSellerProvider(
            $resource,
            $this->timezone,
            $this->createMock(LoggerInterface::class),
            $categoryCollectionFactory
        );

        $this->assertSame(
            [10 => ['rank' => 2, 'category_id' => 6]],
            $provider->getBadges(1, [10], 10, Period::PERIOD_MONTH, 2)
        );
    }

    /**
     * A missing report table is remembered for the request only: once the
     * aggregation cron has created it, the next request in the same worker
     * must see it.
     *
     * @return void
     */
    public function testResetStateForgetsResolvedTables(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->expects($this->exactly(2))
            ->method('isTableExists')
            ->willReturnOnConsecutiveCalls(false, true);

        $resource = $this->createMock(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnArgument(0);

        $provider = new BestSellerProvider(
            $resource,
            $this->timezone,
            $this->createMock(LoggerInterface::class),
            $this->createMock(CategoryCollectionFactory::class)
        );
        $resolve = new \ReflectionMethod($provider, 'resolveAggregatedTable');

        $this->assertNull($resolve->invoke($provider, Period::PERIOD_MONTH));
        // Memoized within the request: no second metadata round trip.
        $this->assertNull($resolve->invoke($provider, Period::PERIOD_MONTH));

        $provider->_resetState();

        $this->assertSame(
            'sales_bestsellers_aggregated_monthly',
            $resolve->invoke($provider, Period::PERIOD_MONTH)
        );
    }

    /**
     * @param float[] $sortedDesc
     * @param float $qty
     * @return int
     */
    private function countHigher(array $sortedDesc, float $qty): int
    {
        $method = new \ReflectionMethod($this->provider, 'countHigher');

        return $method->invoke($this->provider, $sortedDesc, $qty);
    }

    /**
     * @param string $period
     * @param int $storeId
     * @return string
     */
    private function periodStart(string $period, int $storeId = 1): string
    {
        $method = new \ReflectionMethod($this->provider, 'getPeriodStart');

        return $method->invoke($this->provider, $storeId, $period);
    }
}
