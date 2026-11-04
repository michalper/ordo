<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\Campaign;

use Ordo\Automation\Api\Data\CampaignTriggerInterface;

/**
 * Curated, ready-to-use campaign starting points, each a payload shaped exactly like
 * Controller\Adminhtml\Campaign\Export produces - handed straight to CampaignImporter::import(),
 * no new import logic needed. Controller\Adminhtml\Campaign\Library renders these as a gallery;
 * Controller\Adminhtml\Campaign\ImportFromLibrary does the actual import on "Use this Template".
 *
 * Every trigger/condition/action type below is checked against what's actually registered in
 * etc/di.xml's ConditionPool/ActionPool and Api\Data\CampaignTriggerInterface's real constants -
 * see docs/adr/0020-campaign-template-library.md for why a 4th, win-back-style template isn't
 * included: this engine has no per-customer recency-based scheduled trigger (recurring_schedule/
 * scheduled_at fire once per campaign on a schedule with no customer_id in context at all, see
 * Model\Campaign\ScheduledTriggerScanner::fire() - every per-customer condition/action would
 * silently never fire). Every imported campaign starts disabled - the admin reviews and enables
 * manually, which is also how the two genuinely merchant-specific gaps below get caught before
 * anything sends: generate_coupon's rule_id is left blank (no cart price rule ships with this
 * module to safely guess - a bad rule_id is caught by GenerateCoupon and only logged, so the
 * campaign would otherwise silently send with no coupon), and the one send_email action per
 * template using the generic ordo_campaign_generic template (rather than a dedicated one) leaves
 * subject/body to the merchant to write.
 */
class TemplateLibrary
{
    /**
     * @return array<int, array{id: string, name: string, description: string, payload: array<string, mixed>}>
     */
    public function getTemplates(): array
    {
        return [
            $this->welcomeSeries(),
            $this->cartAbandonmentFollowUp(),
            $this->vipUpsell(),
        ];
    }

    /**
     * @return array{id: string, name: string, description: string, payload: array<string, mixed>}|null
     */
    public function getTemplate(string $id): ?array
    {
        foreach ($this->getTemplates() as $template) {
            if ($template['id'] === $id) {
                return $template;
            }
        }

        return null;
    }

    /**
     * @return array{id: string, name: string, description: string, payload: array<string, mixed>}
     */
    private function welcomeSeries(): array
    {
        return [
            'id' => 'welcome_series',
            'name' => (string) __('Welcome Series'),
            'description' => (string) __(
                'Greets a new customer right after registration, then follows up a few days '
                . 'later - a simple two-touch onboarding sequence.'
            ),
            'payload' => [
                'export_type' => 'ordo_campaign',
                'name' => (string) __('Welcome Series'),
                'enabled' => false,
                'condition_logic' => 'all',
                'triggers' => [
                    ['trigger_event' => CampaignTriggerInterface::TRIGGER_CUSTOMER_REGISTERED, 'params' => []],
                ],
                'conditions' => [],
                'actions' => [
                    [
                        'type' => 'send_email',
                        'params' => ['template' => 'ordo_welcome_email'],
                        'delay_minutes' => 0,
                    ],
                    [
                        'type' => 'send_email',
                        'params' => ['template' => 'ordo_campaign_generic'],
                        'delay_minutes' => 4320,
                    ],
                ],
            ],
        ];
    }

    /**
     * @return array{id: string, name: string, description: string, payload: array<string, mixed>}
     */
    private function cartAbandonmentFollowUp(): array
    {
        return [
            'id' => 'cart_abandonment_follow_up',
            'name' => (string) __('Cart Abandonment Follow-up'),
            'description' => (string) __(
                'Layers a coupon onto cart recovery, trailing shortly after the built-in '
                . 'abandoned cart reminder email - doesn\'t replace it.'
            ),
            'payload' => [
                'export_type' => 'ordo_campaign',
                'name' => (string) __('Cart Abandonment Follow-up'),
                'enabled' => false,
                'condition_logic' => 'all',
                'triggers' => [
                    ['trigger_event' => CampaignTriggerInterface::TRIGGER_CART_ABANDONED, 'params' => []],
                ],
                'conditions' => [],
                'actions' => [
                    [
                        'type' => 'generate_coupon',
                        'params' => ['rule_id' => '', 'prefix' => 'COMEBACK'],
                        'delay_minutes' => 0,
                    ],
                    [
                        'type' => 'send_email',
                        'params' => ['template' => 'ordo_campaign_generic'],
                        'delay_minutes' => 60,
                    ],
                ],
            ],
        ];
    }

    /**
     * @return array{id: string, name: string, description: string, payload: array<string, mixed>}
     */
    private function vipUpsell(): array
    {
        return [
            'id' => 'vip_upsell',
            'name' => (string) __('VIP Upsell'),
            'description' => (string) __(
                'Fires the moment a customer\'s engagement score crosses the configured '
                . 'threshold - recommends products, then follows up by email.'
            ),
            'payload' => [
                'export_type' => 'ordo_campaign',
                'name' => (string) __('VIP Upsell'),
                'enabled' => false,
                'condition_logic' => 'all',
                'triggers' => [
                    ['trigger_event' => CampaignTriggerInterface::TRIGGER_SCORE_THRESHOLD_CROSSED, 'params' => []],
                ],
                'conditions' => [],
                'actions' => [
                    [
                        'type' => 'add_product_recommendations',
                        'params' => [],
                        'delay_minutes' => 0,
                    ],
                    [
                        'type' => 'send_email',
                        'params' => ['template' => 'ordo_campaign_generic'],
                        'delay_minutes' => 0,
                    ],
                ],
            ],
        ];
    }
}
