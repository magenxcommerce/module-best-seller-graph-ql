<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\BestSellerGraphQl\Test\Unit\Model;

use Magenx\BestSellerGraphQl\Model\Config;
use Magenx\BestSellerGraphQl\Model\Config\Source\Period;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @see Config
 */
class ConfigTest extends TestCase
{
    /**
     * @var ScopeConfigInterface&MockObject
     */
    private $scopeConfig;

    /**
     * @var Config
     */
    private $config;

    /**
     * @inheritDoc
     */
    protected function setUp(): void
    {
        $this->scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $this->config = new Config($this->scopeConfig);
    }

    /**
     * A stored count is used as-is.
     *
     * @return void
     */
    public function testCountUsesStoredValue(): void
    {
        $this->stubValue('magenx_bestseller/general/count', '35');

        $this->assertSame(35, $this->config->getCount(1));
    }

    /**
     * An unset or nonsensical count must not produce an empty list; the default
     * takes over instead.
     *
     * @return void
     */
    public function testCountFallsBackToDefault(): void
    {
        foreach (['0', '', null, '-5', 'not a number'] as $stored) {
            $this->stubValue('magenx_bestseller/general/count', $stored);

            $this->assertSame(
                20,
                $this->config->getCount(1),
                sprintf('Stored count %s should fall back to the default', var_export($stored, true))
            );
        }
    }

    /**
     * Only the three periods that map onto a real aggregated table are accepted.
     *
     * @return void
     */
    public function testPeriodIsRestrictedToKnownValues(): void
    {
        foreach ([Period::PERIOD_DAY, Period::PERIOD_MONTH, Period::PERIOD_YEAR] as $stored) {
            $this->stubValue('magenx_bestseller/general/period', $stored);

            $this->assertSame($stored, $this->config->getPeriod(1));
        }

        foreach (['weekly', '', null, 'daily'] as $stored) {
            $this->stubValue('magenx_bestseller/general/period', $stored);

            $this->assertSame(
                Period::PERIOD_MONTH,
                $this->config->getPeriod(1),
                sprintf('Stored period %s should fall back to month', var_export($stored, true))
            );
        }
    }

    /**
     * @return void
     */
    public function testBadgeMaxRankFallsBackToDefault(): void
    {
        $this->stubValue('magenx_bestseller/badge/max_rank', '0');

        $this->assertSame(10, $this->config->getBadgeMaxRank(1));
    }

    /**
     * The badge switch is gated on the master switch, so disabling the feature
     * disables the badge too even when its own flag is still on.
     *
     * @return void
     */
    public function testBadgeIsDisabledWhenFeatureIsDisabled(): void
    {
        $this->stubFlags(['magenx_bestseller/general/enabled' => false, 'magenx_bestseller/badge/enabled' => true]);

        $this->assertFalse($this->config->isBadgeEnabled(1));
    }

    /**
     * @return void
     */
    public function testBadgeIsEnabledOnlyWhenBothFlagsAreSet(): void
    {
        $this->stubFlags(['magenx_bestseller/general/enabled' => true, 'magenx_bestseller/badge/enabled' => true]);
        $this->assertTrue($this->config->isBadgeEnabled(1));

        $this->stubFlags(['magenx_bestseller/general/enabled' => true, 'magenx_bestseller/badge/enabled' => false]);
        $this->assertFalse($this->config->isBadgeEnabled(1));
    }

    /**
     * Make the scope config answer getValue() for one path.
     *
     * @param string $path
     * @param string|null $value
     * @return void
     */
    private function stubValue(string $path, ?string $value): void
    {
        $this->scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $this->scopeConfig->method('getValue')
            ->willReturnCallback(
                static fn (string $requested) => $requested === $path ? $value : null
            );
        $this->config = new Config($this->scopeConfig);
    }

    /**
     * Make the scope config answer isSetFlag() from a path => bool map.
     *
     * @param array<string, bool> $flags
     * @return void
     */
    private function stubFlags(array $flags): void
    {
        $this->scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $this->scopeConfig->method('isSetFlag')
            ->willReturnCallback(
                static fn (string $path, string $scope, $scopeId) => $flags[$path] ?? false
            );
        $this->config = new Config($this->scopeConfig);
    }

    /**
     * Guard the scope constant the config reads under, since a change there
     * would silently stop store-level overrides from applying.
     *
     * @return void
     */
    public function testReadsAtStoreScope(): void
    {
        $this->scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $this->scopeConfig->expects($this->once())
            ->method('getValue')
            ->with('magenx_bestseller/general/count', ScopeInterface::SCOPE_STORE, 7)
            ->willReturn('20');
        $this->config = new Config($this->scopeConfig);

        $this->config->getCount(7);
    }
}
