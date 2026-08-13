<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\BestSellerGraphQl\Model;

use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Answers "which categories may this store rank against?".
 *
 * Best-seller data is read straight from report and catalog tables, which are
 * global — nothing in them is scoped to a store view. Without an explicit check
 * a request could therefore rank against, or ask for, a category belonging to a
 * completely different store's tree. Every category id entering the module is
 * validated here first.
 */
class StoreCategoryTree
{
    /**
     * @param StoreManagerInterface $storeManager
     * @param CategoryRepositoryInterface $categoryRepository
     */
    public function __construct(
        private readonly StoreManagerInterface $storeManager,
        private readonly CategoryRepositoryInterface $categoryRepository
    ) {
    }

    /**
     * The root category of the store's group, or 0 when it cannot be resolved.
     *
     * @param StoreInterface $store
     * @return int
     */
    public function getRootCategoryId(StoreInterface $store): int
    {
        try {
            $group = $this->storeManager->getGroup((int) $store->getStoreGroupId());
        } catch (NoSuchEntityException $e) {
            return 0;
        }

        return (int) $group->getRootCategoryId();
    }

    /**
     * Whether the category exists, is active, and sits inside the store's tree.
     *
     * Matches on the materialized `path` column, so the store root itself and
     * any of its descendants qualify while another store's tree never does.
     *
     * @param int $categoryId
     * @param StoreInterface $store
     * @return bool
     */
    public function isInStoreTree(int $categoryId, StoreInterface $store): bool
    {
        $rootCategoryId = $this->getRootCategoryId($store);
        if ($rootCategoryId < 1) {
            return false;
        }

        try {
            $category = $this->categoryRepository->get($categoryId, (int) $store->getId());
        } catch (NoSuchEntityException $e) {
            return false;
        }

        if (!$category->getIsActive()) {
            return false;
        }

        $rootPath = '1/' . $rootCategoryId;
        $path = (string) $category->getPath();

        return $path === $rootPath || str_starts_with($path, $rootPath . '/');
    }
}
