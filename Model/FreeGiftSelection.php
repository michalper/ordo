<?php
declare(strict_types=1);

namespace Ordo\Automation\Model;

use Magento\Framework\DataObject;
use Ordo\Automation\Api\Data\FreeGiftSelectionInterface;

class FreeGiftSelection extends DataObject implements FreeGiftSelectionInterface
{
    public function getSkus(): array
    {
        return (array) $this->getData('skus');
    }

    public function setSkus(array $skus): self
    {
        $this->setData('skus', $skus);
        return $this;
    }
}
