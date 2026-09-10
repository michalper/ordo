<?php
declare(strict_types=1);

namespace Ordo\Automation\Block\Adminhtml\Campaign;

use Magento\Framework\Registry;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Ordo\Automation\Model\Campaign;
use Ordo\Automation\Model\CampaignFunnelStats;

/**
 * Read-only view of one campaign's funnel (sent → delivered → opened → clicked → converted),
 * one row per split-test variant — reads the same 'ordo_campaign' registry entry
 * Block\Adminhtml\Campaign\Edit\Flow already reads, so this needs no controller changes of its
 * own to know which campaign is being edited.
 */
class FunnelViewModel implements ArgumentInterface
{
    public function __construct(
        private readonly Registry $registry,
        private readonly CampaignFunnelStats $campaignFunnelStats
    ) {
    }

    private function getCampaign(): ?Campaign
    {
        $campaign = $this->registry->registry('ordo_campaign');
        return $campaign instanceof Campaign ? $campaign : null;
    }

    public function hasCampaign(): bool
    {
        $campaign = $this->getCampaign();
        return $campaign instanceof Campaign && (bool) $campaign->getEntityId();
    }

    /**
     * @return array<int, array{
     *     variant: string|null,
     *     sent: int,
     *     delivered: int,
     *     opened: int,
     *     clicked: int,
     *     converted: int,
     *     conversion_rate: float,
     *     revenue: float
     * }>
     */
    public function getFunnelRows(): array
    {
        $campaign = $this->getCampaign();
        if (!$campaign || !$campaign->getEntityId()) {
            return [];
        }

        return $this->campaignFunnelStats->getForCampaign((int) $campaign->getEntityId());
    }

    /**
     * True once any row has a non-null variant — drives whether the template shows a "Variant"
     * column at all, so a campaign that never used a split action doesn't show an empty/dashed
     * column for no reason.
     */
    public function hasVariants(): bool
    {
        return array_any($this->getFunnelRows(), fn ($row) => $row['variant'] !== null);
    }

    public function formatCurrency(float $amount): string
    {
        return number_format($amount, 2);
    }
}
