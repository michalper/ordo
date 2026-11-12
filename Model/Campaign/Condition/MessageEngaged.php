<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\Campaign\Condition;

use Ordo\Automation\Api\Campaign\BulkResolvableConditionInterface;
use Ordo\Automation\Api\Campaign\ConditionInterface;
use Ordo\Automation\Model\Campaign\OptionSourceFlattener;
use Ordo\Automation\Model\Config\Source\CampaignOptions;
use Ordo\Automation\Model\Config\Source\MessageEngagementType;
use Ordo\Automation\Model\Email\MessageEngagementResolver;

/**
 * Params: {"campaign_id": 42, "engagement_type": "opened"|"clicked"|"read"
 * (Model\MessageLogEvent::TYPE_*), "engagement_within_days": 14}. Context must include
 * "customer_id" (int). Reads data a prior campaign send's own send_email/send_whatsapp action and
 * the SendGrid Event Webhook (Controller\Email\StatusCallback)/Meta Cloud API webhook
 * (Controller\WhatsApp\Webhook) already captured - no tracking of its own, see docs/adr/0016 and
 * docs/adr/0051. Named "engagement_*", not "event_*"/"within_days" like EventOccurred's own
 * params, so the admin form fields for the two conditions never collide - they read different
 * source tables with different retention stories.
 */
class MessageEngaged implements ConditionInterface, BulkResolvableConditionInterface
{
    public function __construct(
        private readonly MessageEngagementResolver $messageEngagementResolver,
        private readonly CampaignOptions $campaignOptions,
        private readonly MessageEngagementType $messageEngagementTypeOptions
    ) {
    }

    public function isSatisfied(array $context, array $params): bool
    {
        $customerId = $context['customer_id'] ?? null;
        if (!is_numeric($customerId)) {
            return false;
        }

        [$campaignId, $eventType, $withinDays] = $this->readParams($params);
        if ($campaignId === null) {
            return false;
        }

        return $this->messageEngagementResolver->hasEngaged(
            (int) $customerId,
            $campaignId,
            $eventType,
            $withinDays
        );
    }

    public function resolveCustomerIds(array $params): array
    {
        [$campaignId, $eventType, $withinDays] = $this->readParams($params);
        if ($campaignId === null) {
            return [];
        }

        return $this->messageEngagementResolver->getCustomerIdsWithEngagement($campaignId, $eventType, $withinDays);
    }

    /**
     * @param array<string, mixed> $params
     * @return array{0: int|null, 1: string, 2: int}
     */
    private function readParams(array $params): array
    {
        $campaignId = $params['campaign_id'] ?? null;
        if (!is_numeric($campaignId) || (int) $campaignId <= 0) {
            return [null, '', 0];
        }

        $eventType = trim((string) ($params['engagement_type'] ?? ''));
        $withinDays = (int) ($params['engagement_within_days'] ?? 0);

        return [(int) $campaignId, $eventType, $withinDays];
    }

    public function getParamFields(): array
    {
        return [
            [
                'name' => 'campaign_id',
                'label' => (string) __('Campaign'),
                'options' => OptionSourceFlattener::toMap($this->campaignOptions),
            ],
            [
                'name' => 'engagement_type',
                'label' => (string) __('Opened, clicked, or read (WhatsApp)'),
                'options' => OptionSourceFlattener::toMap($this->messageEngagementTypeOptions),
            ],
            ['name' => 'engagement_within_days', 'label' => (string) __('Within days')],
        ];
    }
}
