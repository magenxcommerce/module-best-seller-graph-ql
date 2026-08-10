<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\BestSellerGraphQl\Model;

use Magenx\BestSellerGraphQl\Model\Config\Source\Period;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * Typed access to the magenx_bestseller/* store configuration.
 */
class Config
{
    private const XML_PATH_ENABLED = 'magenx_bestseller/general/enabled';
    private const XML_PATH_COUNT = 'magenx_bestseller/general/count';
    private const XML_PATH_PERIOD = 'magenx_bestseller/general/period';
    private const XML_PATH_BADGE_ENABLED = 'magenx_bestseller/badge/enabled';
    private const XML_PATH_BADGE_MAX_RANK = 'magenx_bestseller/badge/max_rank';

    private const DEFAULT_COUNT = 20;
    private const DEFAULT_BADGE_MAX_RANK = 10;

    /**
     * @param ScopeConfigInterface $scopeConfig
     */
    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    /**
     * Whether the best sellers feature is enabled.
     *
     * @param int|null $storeId
     * @return bool
     */
    public function isEnabled(?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_ENABLED, ScopeInterface::SCOPE_STORE, $storeId);
    }

    /**
     * How many products the best-seller lists return.
     *
     * @param int|null $storeId
     * @return int
     */
    public function getCount(?int $storeId = null): int
    {
        $value = (int) $this->scopeConfig->getValue(self::XML_PATH_COUNT, ScopeInterface::SCOPE_STORE, $storeId);

        return $value > 0 ? $value : self::DEFAULT_COUNT;
    }

    /**
     * The aggregated report period (day|month|year).
     *
     * @param int|null $storeId
     * @return string
     */
    public function getPeriod(?int $storeId = null): string
    {
        $value = (string) $this->scopeConfig->getValue(self::XML_PATH_PERIOD, ScopeInterface::SCOPE_STORE, $storeId);

        return in_array($value, [Period::PERIOD_DAY, Period::PERIOD_MONTH, Period::PERIOD_YEAR], true)
            ? $value
            : Period::PERIOD_MONTH;
    }

    /**
     * Whether the per-category product badge is enabled.
     *
     * @param int|null $storeId
     * @return bool
     */
    public function isBadgeEnabled(?int $storeId = null): bool
    {
        return $this->isEnabled($storeId)
            && $this->scopeConfig->isSetFlag(self::XML_PATH_BADGE_ENABLED, ScopeInterface::SCOPE_STORE, $storeId);
    }

    /**
     * The highest rank that still earns a badge.
     *
     * @param int|null $storeId
     * @return int
     */
    public function getBadgeMaxRank(?int $storeId = null): int
    {
        $value = (int) $this->scopeConfig->getValue(self::XML_PATH_BADGE_MAX_RANK, ScopeInterface::SCOPE_STORE, $storeId);

        return $value > 0 ? $value : self::DEFAULT_BADGE_MAX_RANK;
    }
}
