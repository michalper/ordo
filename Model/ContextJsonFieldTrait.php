<?php
declare(strict_types=1);

namespace Ordo\Automation\Model;

/**
 * The JSON-encoded trigger-context accessor pair shared by every row that persists a campaign
 * dispatch context for later (CampaignScheduledAction, CampaignActionRetry, MessageSendRetry) —
 * a trait, not a base class, for the same reason RetryRecordFieldsTrait is one: these otherwise
 * extend Magento's AbstractModel directly and share no other behaviour. Each class declares its
 * own CONTEXT constant, so self:: resolves per class.
 */
trait ContextJsonFieldTrait
{
    /**
     * @return array<string, mixed>
     */
    public function getContext(): array
    {
        return ParamsMap::fromJson((string) $this->getData(self::CONTEXT));
    }

    /**
     * @param array<string, mixed> $context
     */
    public function setContext(array $context): self
    {
        $this->setData(self::CONTEXT, (string) json_encode($context));
        return $this;
    }
}
