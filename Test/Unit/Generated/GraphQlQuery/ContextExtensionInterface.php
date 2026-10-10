<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magento\GraphQl\Model\Query;

use Magento\Framework\Api\ExtensionAttributesInterface;
use Magento\Store\Api\Data\StoreInterface;

/**
 * Test-only stand-in for the extension-attributes interface Magento generates
 * from extension_attributes.xml (Magento_StoreGraphQl adds `store`).
 *
 * Unit tests run without a Magento install's code generator, so the generated
 * interface does not exist and cannot be mocked. Registered through
 * autoload-dev, which Composer only honours for the root package: in a real
 * installation the generated interface is used and this file is never loaded.
 */
interface ContextExtensionInterface extends ExtensionAttributesInterface
{
    /**
     * @return StoreInterface|null
     */
    public function getStore();

    /**
     * @param StoreInterface $store
     * @return $this
     */
    public function setStore($store);
}
