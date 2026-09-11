<?php
declare(strict_types=1);

namespace Ordo\Automation\Block\Adminhtml\Setup;

use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\ResourceModel\Campaign\CollectionFactory as CampaignCollectionFactory;
use Ordo\Automation\Model\ResourceModel\Segment\CollectionFactory as SegmentCollectionFactory;

/**
 * A fresh install has no cross-entity onboarding hook today - only per-grid empty-state CTAs
 * (ROADMAP.md "setup wizard/guided first-run flow" gap). This is a lightweight first cut:
 * a read-only checklist sequencing the real dependency order (configure a channel -> build a
 * segment -> build a campaign), each step's "done" state computed live from real data rather
 * than a new persisted onboarding-progress table - simpler and just as accurate for a checklist
 * that only ever needs "has this actually happened yet", never a dismiss/skip state of its own.
 */
class SetupWizardViewModel implements ArgumentInterface
{
    public function __construct(
        private readonly Config $config,
        private readonly SegmentCollectionFactory $segmentCollectionFactory,
        private readonly CampaignCollectionFactory $campaignCollectionFactory,
        private readonly UrlInterface $urlBuilder
    ) {
    }

    /**
     * @return array<int, array{title: string, desc: string, done: bool, url: string}>
     */
    public function getSteps(): array
    {
        return [
            [
                'title' => (string) __('Configure a messaging channel'),
                'desc' => (string) __(
                    'SMS (Twilio) or WhatsApp (Meta) — a campaign\'s send_sms/send_whatsapp action '
                    . 'silently fails at dispatch time until credentials are set here.'
                ),
                'done' => $this->isChannelConfigured(),
                'url' => $this->urlBuilder->getUrl('adminhtml/system_config/edit', ['section' => 'ordo_automation']),
            ],
            [
                'title' => (string) __('Build a segment'),
                'desc' => (string) __(
                    'A saved, reusable set of customer conditions — reference it from any campaign '
                    . 'via the "In Segment" condition instead of repeating the same conditions everywhere.'
                ),
                'done' => $this->hasAnySegment(),
                'url' => $this->urlBuilder->getUrl('ordo/segment/index'),
            ],
            [
                'title' => (string) __('Build a campaign'),
                'desc' => (string) __(
                    'A trigger, plus whatever conditions/actions should run when it fires — the Flow '
                    . 'canvas is the fastest way to build one.'
                ),
                'done' => $this->hasAnyCampaign(),
                'url' => $this->urlBuilder->getUrl('ordo/campaign/index'),
            ],
        ];
    }

    /**
     * All three steps done = nothing left for this wizard to actually surface — the caller uses
     * this to decide whether to show the wizard at all on the dashboard, vs. only on its own
     * dedicated page.
     */
    public function isComplete(): bool
    {
        return array_all($this->getSteps(), fn ($step) => $step['done']);
    }

    private function isChannelConfigured(): bool
    {
        $smsConfigured = $this->config->isSmsEnabled() && $this->config->getTwilioAccountSid() !== '';
        $whatsAppConfigured = $this->config->isWhatsAppEnabled() && $this->config->getWhatsAppAccessToken() !== '';

        return $smsConfigured || $whatsAppConfigured;
    }

    private function hasAnySegment(): bool
    {
        return $this->segmentCollectionFactory->create()->getSize() > 0;
    }

    private function hasAnyCampaign(): bool
    {
        return $this->campaignCollectionFactory->create()->getSize() > 0;
    }
}
