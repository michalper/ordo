<?php
declare(strict_types=1);

namespace Ordo\Automation\Model;

use Magento\Framework\Model\AbstractModel;
use Ordo\Automation\Model\ResourceModel\CampaignDispatchDeadLetter as CampaignDispatchDeadLetterResource;

/**
 * A persisted record of a CampaignDispatchConsumer message that couldn't be processed - see
 * etc/db_schema.xml's ordo_campaign_dispatch_dead_letter comment. Plain data holder, insert-only;
 * nothing currently re-processes these automatically.
 */
class CampaignDispatchDeadLetter extends AbstractModel
{
    public const ENTITY_ID = 'entity_id';
    public const TRIGGER_EVENT = 'trigger_event';
    public const MESSAGE = 'message';
    public const ERROR = 'error';

    protected function _construct(): void
    {
        $this->_init(CampaignDispatchDeadLetterResource::class);
    }

    public function setTriggerEvent(?string $triggerEvent): self
    {
        $this->setData(self::TRIGGER_EVENT, $triggerEvent);
        return $this;
    }

    public function setMessage(string $message): self
    {
        $this->setData(self::MESSAGE, $message);
        return $this;
    }

    public function setError(string $error): self
    {
        $this->setData(self::ERROR, $error);
        return $this;
    }
}
