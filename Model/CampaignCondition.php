<?php
declare(strict_types=1);

namespace Ordo\Automation\Model;

use Magento\Framework\Model\AbstractModel;
use Ordo\Automation\Model\ResourceModel\Campaign\Condition as CampaignConditionResource;

class CampaignCondition extends AbstractModel
{
    protected function _construct(): void
    {
        $this->_init(CampaignConditionResource::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function getParams(): array
    {
        $raw = (string) $this->getData('params');
        $decoded = $raw !== '' ? json_decode($raw, true) : [];
        return is_array($decoded) ? $decoded : [];
    }
}
