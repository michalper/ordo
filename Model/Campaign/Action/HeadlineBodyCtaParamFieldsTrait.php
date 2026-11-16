<?php

declare(strict_types=1);

namespace Ordo\Automation\Model\Campaign\Action;

/**
 * Shared headline/body/cta_label/cta_url getParamFields() entries - confirmed byte-for-byte
 * identical between Notify and ShowPopup before this extraction, since both reuse the same
 * dedicated admin fields (ordo_campaign_form.xml's switcherConfig shows them for both action
 * types). ShowPopup appends its own reward_tiers field after these.
 */
trait HeadlineBodyCtaParamFieldsTrait
{
    /**
     * @return array<int, array{name: string, label: string}>
     */
    protected function headlineBodyCtaParamFields(): array
    {
        return [
            ['name' => 'headline', 'label' => (string) __('Popup headline')],
            ['name' => 'body', 'label' => (string) __('Popup body')],
            ['name' => 'cta_label', 'label' => (string) __('CTA button label')],
            ['name' => 'cta_url', 'label' => (string) __('CTA button URL')],
        ];
    }
}
