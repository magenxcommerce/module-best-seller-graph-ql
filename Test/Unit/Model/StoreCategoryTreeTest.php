<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\BestSellerGraphQl\Test\Unit\Model;

use Magenx\BestSellerGraphQl\Model\StoreCategoryTree;
use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Catalog\Api\Data\CategoryInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Api\Data\GroupInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * This class is the module's store boundary: the report and catalog tables it
 * reads are global, so these are the checks that stop one store view ranking
 * against — or reading — another's categories.
 *
 * @see StoreCategoryTree
 */
class StoreCategoryTreeTest extends TestCase
{
    /**
     * @var StoreManagerInterface&MockObject
     */
    private $storeManager;

    /**
     * @var CategoryRepositoryInterface&MockObject
     */
    private $categoryRepository;

    /**
     * @var StoreCategoryTree
     */
    private $tree;

    /**
     * @inheritDoc
     */
    protected function setUp(): void
    {
        $this->storeManager = $this->createMock(StoreManagerInterface::class);
        $this->categoryRepository = $this->createMock(CategoryRepositoryInterface::class);
        $this->tree = new StoreCategoryTree($this->storeManager, $this->categoryRepository);
    }

    /**
     * @return void
     */
    public function testRootCategoryIdComesFromTheStoreGroup(): void
    {
        $this->stubRootCategory(5);

        $this->assertSame(5, $this->tree->getRootCategoryId($this->store()));
    }

    /**
     * A store whose group has gone missing must resolve to 0, which callers
     * treat as "rank nothing" rather than "rank everything".
     *
     * @return void
     */
    public function testRootCategoryIdIsZeroWhenTheGroupIsMissing(): void
    {
        $this->storeManager->method('getGroup')->willThrowException(new NoSuchEntityException(__('no group')));

        $this->assertSame(0, $this->tree->getRootCategoryId($this->store()));
    }

    /**
     * @return void
     */
    public function testCategoryInsideTheStoreTreeIsAccepted(): void
    {
        $this->stubRootCategory(2);
        $this->stubCategory('1/2/17/42', true);

        $this->assertTrue($this->tree->isInStoreTree(42, $this->store()));
    }

    /**
     * The store root itself is a legitimate scope for a store-wide ranking.
     *
     * @return void
     */
    public function testStoreRootItselfIsAccepted(): void
    {
        $this->stubRootCategory(2);
        $this->stubCategory('1/2', true);

        $this->assertTrue($this->tree->isInStoreTree(2, $this->store()));
    }

    /**
     * The whole point of the check: another store view's tree is refused.
     *
     * @return void
     */
    public function testCategoryFromAnotherStoreTreeIsRejected(): void
    {
        $this->stubRootCategory(2);
        $this->stubCategory('1/3/19', true);

        $this->assertFalse($this->tree->isInStoreTree(19, $this->store()));
    }

    /**
     * A prefix match alone is not enough: root 2 must not swallow root 20.
     *
     * @return void
     */
    public function testRootIdIsMatchedOnPathSegmentsNotPrefix(): void
    {
        $this->stubRootCategory(2);
        $this->stubCategory('1/20/7', true);

        $this->assertFalse($this->tree->isInStoreTree(7, $this->store()));
    }

    /**
     * @return void
     */
    public function testInactiveCategoryIsRejected(): void
    {
        $this->stubRootCategory(2);
        $this->stubCategory('1/2/17', false);

        $this->assertFalse($this->tree->isInStoreTree(17, $this->store()));
    }

    /**
     * @return void
     */
    public function testUnknownCategoryIsRejected(): void
    {
        $this->stubRootCategory(2);
        $this->categoryRepository->method('get')->willThrowException(new NoSuchEntityException(__('no category')));

        $this->assertFalse($this->tree->isInStoreTree(999, $this->store()));
    }

    /**
     * @param int $rootCategoryId
     * @return void
     */
    private function stubRootCategory(int $rootCategoryId): void
    {
        $group = $this->createMock(GroupInterface::class);
        $group->method('getRootCategoryId')->willReturn($rootCategoryId);
        $this->storeManager->method('getGroup')->willReturn($group);
    }

    /**
     * @param string $path
     * @param bool $isActive
     * @return void
     */
    private function stubCategory(string $path, bool $isActive): void
    {
        $category = $this->createMock(CategoryInterface::class);
        $category->method('getPath')->willReturn($path);
        $category->method('getIsActive')->willReturn($isActive);
        $this->categoryRepository->method('get')->willReturn($category);
    }

    /**
     * @return StoreInterface&MockObject
     */
    private function store()
    {
        $store = $this->createMock(StoreInterface::class);
        $store->method('getId')->willReturn(1);
        $store->method('getStoreGroupId')->willReturn(1);

        return $store;
    }
}
