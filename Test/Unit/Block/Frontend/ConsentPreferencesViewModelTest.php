<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Block\Frontend;

use Magento\Framework\Exception\NoSuchEntityException;
use Ordo\Automation\Api\ConsentPreferencesManagementInterface;
use Ordo\Automation\Block\Frontend\ConsentPreferencesViewModel;
use Ordo\Automation\Model\ConsentChannel;
use Ordo\Automation\Model\ConsentChannelPreference;
use Ordo\Automation\Model\ConsentPreferences;
use Ordo\Automation\Model\ConsentTopic;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

class ConsentPreferencesViewModelTest extends TestCase
{
    private ConsentPreferencesManagementInterface $consentPreferencesManagement;

    protected function setUp(): void
    {
        $this->consentPreferencesManagement = $this->createMock(ConsentPreferencesManagementInterface::class);
    }

    private function makeViewModel(): ConsentPreferencesViewModel
    {
        return new ConsentPreferencesViewModel($this->consentPreferencesManagement);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testGetDeliveryChannelsListsEveryMessageChannelButNotAds(): void
    {
        self::assertSame(
            [ConsentChannel::Email, ConsentChannel::Sms, ConsentChannel::Push, ConsentChannel::WhatsApp, ConsentChannel::Rcs],
            $this->makeViewModel()->getDeliveryChannels()
        );
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testIsChannelConsentedReadsTheMatchingTypedGetter(): void
    {
        $preferences = (new ConsentPreferences())
            ->setEmail((new ConsentChannelPreference())->setConsented(false))
            ->setSms((new ConsentChannelPreference())->setConsented(true))
            ->setPush((new ConsentChannelPreference())->setConsented(false))
            ->setWhatsapp((new ConsentChannelPreference())->setConsented(true))
            ->setRcs((new ConsentChannelPreference())->setConsented(false))
            ->setAds(true);
        $this->consentPreferencesManagement->method('getMyPreferences')->willReturn($preferences);

        $viewModel = $this->makeViewModel();

        self::assertFalse($viewModel->isChannelConsented(ConsentChannel::Email));
        self::assertTrue($viewModel->isChannelConsented(ConsentChannel::Sms));
        self::assertFalse($viewModel->isChannelConsented(ConsentChannel::Push));
        self::assertTrue($viewModel->isChannelConsented(ConsentChannel::WhatsApp));
        self::assertFalse($viewModel->isChannelConsented(ConsentChannel::Rcs));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testIsTopicConsentedReadsPromotionalOrAccountUpdatesFromTheMatchingChannel(): void
    {
        $preferences = (new ConsentPreferences())
            ->setEmail((new ConsentChannelPreference())->setPromotional(true)->setAccountUpdates(false))
            ->setSms(new ConsentChannelPreference())
            ->setPush(new ConsentChannelPreference())
            ->setWhatsapp(new ConsentChannelPreference())
            ->setRcs(new ConsentChannelPreference())
            ->setAds(true);
        $this->consentPreferencesManagement->method('getMyPreferences')->willReturn($preferences);

        $viewModel = $this->makeViewModel();

        self::assertTrue($viewModel->isTopicConsented(ConsentChannel::Email, ConsentTopic::Promotional));
        self::assertFalse($viewModel->isTopicConsented(ConsentChannel::Email, ConsentTopic::AccountUpdates));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testIsAdsConsentedReadsTheAdsGetter(): void
    {
        $preferences = (new ConsentPreferences())
            ->setEmail(new ConsentChannelPreference())
            ->setSms(new ConsentChannelPreference())
            ->setPush(new ConsentChannelPreference())
            ->setWhatsapp(new ConsentChannelPreference())
            ->setRcs(new ConsentChannelPreference())
            ->setAds(false);
        $this->consentPreferencesManagement->method('getMyPreferences')->willReturn($preferences);

        self::assertFalse($this->makeViewModel()->isAdsConsented());
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testFailsOpenToDefaultsWhenNoAuthenticatedCustomer(): void
    {
        $this->consentPreferencesManagement->method('getMyPreferences')
            ->willThrowException(new NoSuchEntityException(__('No authenticated customer for this request.')));

        $viewModel = $this->makeViewModel();

        self::assertTrue($viewModel->isChannelConsented(ConsentChannel::Email));
        self::assertFalse($viewModel->isTopicConsented(ConsentChannel::Email, ConsentTopic::Promotional));
        self::assertTrue($viewModel->isTopicConsented(ConsentChannel::Email, ConsentTopic::AccountUpdates));
        self::assertTrue($viewModel->isAdsConsented());
    }

    public function testOnlyFetchesPreferencesOnceAcrossMultipleCalls(): void
    {
        $preferences = (new ConsentPreferences())
            ->setEmail(new ConsentChannelPreference())
            ->setSms(new ConsentChannelPreference())
            ->setPush(new ConsentChannelPreference())
            ->setWhatsapp(new ConsentChannelPreference())
            ->setRcs(new ConsentChannelPreference())
            ->setAds(true);
        $this->consentPreferencesManagement->expects(self::once())->method('getMyPreferences')->willReturn($preferences);

        $viewModel = $this->makeViewModel();
        $viewModel->isChannelConsented(ConsentChannel::Email);
        $viewModel->isAdsConsented();
    }
}
