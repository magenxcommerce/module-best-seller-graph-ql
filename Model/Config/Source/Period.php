<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\BestSellerGraphQl\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

/**
 * Report-period options for the Best Sellers admin config.
 *
 * The values map 1:1 onto the sales_bestsellers_aggregated_<period> tables.
 */
class Period implements OptionSourceInterface
{
    public const PERIOD_DAY = 'day';
    public const PERIOD_MONTH = 'month';
    public const PERIOD_YEAR = 'year';

    /**
     * @inheritDoc
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => self::PERIOD_DAY, 'label' => __('Daily')],
            ['value' => self::PERIOD_MONTH, 'label' => __('Monthly')],
            ['value' => self::PERIOD_YEAR, 'label' => __('Yearly')],
        ];
    }
}
