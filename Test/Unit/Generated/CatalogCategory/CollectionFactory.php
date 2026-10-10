<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magento\Catalog\Model\ResourceModel\Category;

/**
 * Test-only stand-in for the factory Magento generates into generated/code.
 *
 * Unit tests run without a Magento install's code generator, so the generated
 * class does not exist and cannot be mocked. Registered through autoload-dev,
 * which Composer only honours for the root package: in a real installation the
 * generated class is used and this file is never loaded (Test/ is also
 * export-ignored from the distributed package).
 */
class CollectionFactory
{
    /**
     * @param array $data
     * @return Collection
     */
    public function create(array $data = [])
    {
        throw new \LogicException('Test stub: mock this factory.');
    }
}
