<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\ResourceModel\ContentBlock;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;
use Ordo\Automation\Model\ContentBlock as ContentBlockModel;
use Ordo\Automation\Model\ResourceModel\ContentBlock as ContentBlockResource;

class Collection extends AbstractCollection
{
    protected function _construct(): void
    {
        $this->_init(ContentBlockModel::class, ContentBlockResource::class);
    }
}
