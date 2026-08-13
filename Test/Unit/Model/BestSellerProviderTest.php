<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\BestSellerGraphQl\Test\Unit\Model;

use Magenx\BestSellerGraphQl\Model\BestSellerProvider;
use Magenx\BestSellerGraphQl\Model\Config\Source\Period;
use Magento\Framework\App\ResourceConnection;
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
            $this->createMock(LoggerInterface::class)
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
