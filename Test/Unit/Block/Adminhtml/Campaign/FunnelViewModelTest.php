<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Block\Adminhtml\Campaign;

use Magento\Framework\Registry;
use Ordo\Automation\Block\Adminhtml\Campaign\FunnelViewModel;
use Ordo\Automation\Model\Campaign;
use Ordo\Automation\Model\CampaignFunnelStats;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

class FunnelViewModelTest extends TestCase
{
    private Registry $registry;
    private CampaignFunnelStats $campaignFunnelStats;

    protected function setUp(): void
    {
        $this->registry = $this->createStub(Registry::class);
        $this->campaignFunnelStats = $this->createStub(CampaignFunnelStats::class);
    }

    private function makeViewModel(): FunnelViewModel
    {
        return new FunnelViewModel($this->registry, $this->campaignFunnelStats);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testHasCampaignFalseWhenNoneRegistered(): void
    {
        $this->registry->method('registry')->willReturnMap([['ordo_campaign', null]]);

        self::assertFalse($this->makeViewModel()->hasCampaign());
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testHasCampaignFalseForUnsavedCampaign(): void
    {
        $campaign = $this->createStub(Campaign::class);
        $campaign->method('getEntityId')->willReturn(null);
        $this->registry->method('registry')->willReturnMap([['ordo_campaign', $campaign]]);

        self::assertFalse($this->makeViewModel()->hasCampaign());
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testGetFunnelRowsReturnsEmptyArrayWithoutACampaign(): void
    {
        $this->registry->method('registry')->willReturnMap([['ordo_campaign', null]]);

        self::assertSame([], $this->makeViewModel()->getFunnelRows());
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testGetFunnelRowsDelegatesToCampaignFunnelStatsForTheRegisteredCampaign(): void
    {
        $campaign = $this->createStub(Campaign::class);
        $campaign->method('getEntityId')->willReturn(5);
        $this->registry->method('registry')->willReturnMap([['ordo_campaign', $campaign]]);

        $row = [
            'variant' => 'a', 'sent' => 1, 'delivered' => 1, 'opened' => 0, 'clicked' => 0,
            'converted' => 0, 'conversion_rate' => 0.0, 'revenue' => 0.0,
        ];
        $this->campaignFunnelStats->method('getForCampaign')->willReturn([$row]);

        self::assertSame([$row], $this->makeViewModel()->getFunnelRows());
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testHasVariantsTrueWhenAnyRowHasANonNullVariant(): void
    {
        $campaign = $this->createStub(Campaign::class);
        $campaign->method('getEntityId')->willReturn(5);
        $this->registry->method('registry')->willReturnMap([['ordo_campaign', $campaign]]);

        $this->campaignFunnelStats->method('getForCampaign')->willReturn([
            ['variant' => null, 'sent' => 1, 'delivered' => 1, 'opened' => 0, 'clicked' => 0, 'converted' => 0, 'conversion_rate' => 0.0, 'revenue' => 0.0],
            ['variant' => 'b', 'sent' => 1, 'delivered' => 1, 'opened' => 0, 'clicked' => 0, 'converted' => 0, 'conversion_rate' => 0.0, 'revenue' => 0.0],
        ]);

        self::assertTrue($this->makeViewModel()->hasVariants());
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testHasVariantsFalseWhenEveryRowHasANullVariant(): void
    {
        $campaign = $this->createStub(Campaign::class);
        $campaign->method('getEntityId')->willReturn(5);
        $this->registry->method('registry')->willReturnMap([['ordo_campaign', $campaign]]);

        $this->campaignFunnelStats->method('getForCampaign')->willReturn([
            ['variant' => null, 'sent' => 1, 'delivered' => 1, 'opened' => 0, 'clicked' => 0, 'converted' => 0, 'conversion_rate' => 0.0, 'revenue' => 0.0],
        ]);

        self::assertFalse($this->makeViewModel()->hasVariants());
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testFormatCurrencyFormatsToTwoDecimals(): void
    {
        self::assertSame('149.99', $this->makeViewModel()->formatCurrency(149.99));
        self::assertSame('0.00', $this->makeViewModel()->formatCurrency(0.0));
    }
}
