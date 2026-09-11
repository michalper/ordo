<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Block\Adminhtml\Setup;

use Magento\Framework\UrlInterface;
use Ordo\Automation\Block\Adminhtml\Setup\SetupWizardViewModel;
use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\ResourceModel\Campaign\Collection as CampaignCollection;
use Ordo\Automation\Model\ResourceModel\Campaign\CollectionFactory as CampaignCollectionFactory;
use Ordo\Automation\Model\ResourceModel\Segment\Collection as SegmentCollection;
use Ordo\Automation\Model\ResourceModel\Segment\CollectionFactory as SegmentCollectionFactory;
use PHPUnit\Framework\TestCase;

class SetupWizardViewModelTest extends TestCase
{
    private function makeViewModel(
        bool $smsEnabled,
        string $twilioSid,
        bool $whatsAppEnabled,
        string $whatsAppToken,
        int $segmentCount,
        int $campaignCount
    ): SetupWizardViewModel {
        $config = $this->createStub(Config::class);
        $config->method('isSmsEnabled')->willReturn($smsEnabled);
        $config->method('getTwilioAccountSid')->willReturn($twilioSid);
        $config->method('isWhatsAppEnabled')->willReturn($whatsAppEnabled);
        $config->method('getWhatsAppAccessToken')->willReturn($whatsAppToken);

        $segmentCollection = $this->createStub(SegmentCollection::class);
        $segmentCollection->method('getSize')->willReturn($segmentCount);
        $segmentCollectionFactory = $this->createStub(SegmentCollectionFactory::class);
        $segmentCollectionFactory->method('create')->willReturn($segmentCollection);

        $campaignCollection = $this->createStub(CampaignCollection::class);
        $campaignCollection->method('getSize')->willReturn($campaignCount);
        $campaignCollectionFactory = $this->createStub(CampaignCollectionFactory::class);
        $campaignCollectionFactory->method('create')->willReturn($campaignCollection);

        $urlBuilder = $this->createStub(UrlInterface::class);
        $urlBuilder->method('getUrl')->willReturn('https://example.test/admin/');

        return new SetupWizardViewModel($config, $segmentCollectionFactory, $campaignCollectionFactory, $urlBuilder);
    }

    public function testGetStepsAllUndoneOnAFreshInstall(): void
    {
        $viewModel = $this->makeViewModel(false, '', false, '', 0, 0);
        $done = array_column($viewModel->getSteps(), 'done');

        self::assertSame([false, false, false], $done);
        self::assertFalse($viewModel->isComplete());
    }

    public function testChannelStepDoneWhenSmsConfigured(): void
    {
        $viewModel = $this->makeViewModel(true, 'AC123', false, '', 0, 0);

        self::assertTrue($viewModel->getSteps()[0]['done']);
    }

    public function testChannelStepDoneWhenWhatsAppConfigured(): void
    {
        $viewModel = $this->makeViewModel(false, '', true, 'token', 0, 0);

        self::assertTrue($viewModel->getSteps()[0]['done']);
    }

    public function testChannelStepNotDoneWhenEnabledButNoCredentials(): void
    {
        $viewModel = $this->makeViewModel(true, '', true, '', 0, 0);

        self::assertFalse($viewModel->getSteps()[0]['done']);
    }

    public function testSegmentAndCampaignStepsReflectRealCounts(): void
    {
        $viewModel = $this->makeViewModel(true, 'AC123', false, '', 3, 5);
        $steps = $viewModel->getSteps();

        self::assertTrue($steps[1]['done']);
        self::assertTrue($steps[2]['done']);
    }

    public function testIsCompleteOnlyWhenEveryStepIsDone(): void
    {
        $viewModel = $this->makeViewModel(true, 'AC123', false, '', 1, 1);

        self::assertTrue($viewModel->isComplete());
    }
}
