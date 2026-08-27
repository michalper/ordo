<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

class FreeGiftOfferTier extends AbstractDb
{
    protected function _construct(): void
    {
        $this->_init('ordo_free_gift_offer_tier', 'entity_id');
    }
}
