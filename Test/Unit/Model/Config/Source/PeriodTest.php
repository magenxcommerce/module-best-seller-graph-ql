<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\BestSellerGraphQl\Test\Unit\Model\Config\Source;

use Magenx\BestSellerGraphQl\Model\Config\Source\Period;
use PHPUnit\Framework\TestCase;

/**
 * @see Period
 */
class PeriodTest extends TestCase
{
    /**
     * The stored values are mapped onto sales_bestsellers_aggregated_<suffix>
     * table names, so an admin must not be able to pick anything else.
     *
     * @return void
     */
    public function testOffersOnlyThePeriodsBackedByATable(): void
    {
        $options = (new Period())->toOptionArray();

        $this->assertSame(
            [Period::PERIOD_DAY, Period::PERIOD_MONTH, Period::PERIOD_YEAR],
            array_column($options, 'value')
        );
    }

    /**
     * @return void
     */
    public function testEveryOptionIsLabelled(): void
    {
        foreach ((new Period())->toOptionArray() as $option) {
            $this->assertArrayHasKey('label', $option);
            $this->assertNotSame('', (string) $option['label']);
        }
    }
}
