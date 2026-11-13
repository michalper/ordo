<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model;

use Magento\Authorization\Model\UserContextInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Ordo\Automation\Model\ConsentChannel;
use Ordo\Automation\Model\ConsentChannelPreference;
use Ordo\Automation\Model\ConsentChannelPreferenceFactory;
use Ordo\Automation\Model\ConsentManager;
use Ordo\Automation\Model\ConsentPreferences;
use Ordo\Automation\Model\ConsentPreferencesFactory;
use Ordo\Automation\Model\ConsentPreferencesManagement;
use Ordo\Automation\Model\ConsentTopic;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

class ConsentPreferencesManagementTest extends TestCase
{
    private ConsentManager $consentManager;
    private UserContextInterface $userContext;
    private ConsentPreferencesFactory $consentPreferencesFactory;
    private ConsentChannelPreferenceFactory $consentChannelPreferenceFactory;

    protected function setUp(): void
    {
        $this->consentManager = $this->createMock(ConsentManager::class);
        $this->userContext = $this->createStub(UserContextInterface::class);

        $this->consentPreferencesFactory = $this->createStub(ConsentPreferencesFactory::class);
        $this->consentPreferencesFactory->method('create')->willReturnCallback(
            fn () => new ConsentPreferences()
        );

        $this->consentChannelPreferenceFactory = $this->createStub(ConsentChannelPreferenceFactory::class);
        $this->consentChannelPreferenceFactory->method('create')->willReturnCallback(
            fn () => new ConsentChannelPreference()
        );
    }

    private function makeManagement(): ConsentPreferencesManagement
    {
        return new ConsentPreferencesManagement(
            $this->consentManager,
            $this->userContext,
            $this->consentPreferencesFactory,
            $this->consentChannelPreferenceFactory
        );
    }

    public function testGetMyPreferencesBuildsOneChannelPreferencePerDeliveryChannelPlusAds(): void
    {
        $this->userContext->method('getUserId')->willReturn(42);
        $this->consentManager->method('getConsentStates')->with(42)->willReturn([
            'email' => false,
            'ads' => false,
        ]);
        $this->consentManager->method('getTopicConsentStates')->willReturn([]);

        $preferences = $this->makeManagement()->getMyPreferences();

        self::assertFalse($preferences->getEmail()->getConsented());
        self::assertFalse($preferences->getEmail()->getPromotional());
        self::assertTrue($preferences->getEmail()->getAccountUpdates());
        self::assertTrue($preferences->getSms()->getConsented());
        self::assertFalse($preferences->getAds());
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testGetMyPreferencesThrowsNoSuchEntityExceptionForAGuest(): void
    {
        $this->userContext->method('getUserId')->willReturn(null);

        $this->expectException(NoSuchEntityException::class);

        $this->makeManagement()->getMyPreferences();
    }

    public function testSetMyChannelConsentDelegatesToConsentManagerAndReturnsFreshPreferences(): void
    {
        $this->userContext->method('getUserId')->willReturn(42);
        $this->consentManager->expects(self::once())->method('setConsent')
            ->with(42, ConsentChannel::Sms, false, 'storefront');
        $this->consentManager->method('getConsentStates')->willReturn([]);
        $this->consentManager->method('getTopicConsentStates')->willReturn([]);

        $preferences = $this->makeManagement()->setMyChannelConsent('sms', false);

        self::assertTrue($preferences->getSms()->getConsented());
    }

    public function testSetMyChannelConsentThrowsLocalizedExceptionForAnInvalidChannel(): void
    {
        $this->userContext->method('getUserId')->willReturn(42);
        $this->consentManager->expects(self::never())->method('setConsent');

        $this->expectException(LocalizedException::class);

        $this->makeManagement()->setMyChannelConsent('bogus', true);
    }

    public function testSetMyTopicConsentDelegatesToConsentManagerAndReturnsFreshPreferences(): void
    {
        $this->userContext->method('getUserId')->willReturn(42);
        $this->consentManager->expects(self::once())->method('setTopicConsent')
            ->with(42, ConsentChannel::WhatsApp, ConsentTopic::Promotional, true, 'storefront');
        $this->consentManager->method('getConsentStates')->willReturn([]);
        $this->consentManager->method('getTopicConsentStates')->willReturn([]);

        $this->makeManagement()->setMyTopicConsent('whatsapp', 'promotional', true);
    }

    public function testSetMyTopicConsentThrowsLocalizedExceptionForAnInvalidChannelOrTopic(): void
    {
        $this->userContext->method('getUserId')->willReturn(42);
        $this->consentManager->expects(self::never())->method('setTopicConsent');

        $this->expectException(LocalizedException::class);

        $this->makeManagement()->setMyTopicConsent('email', 'bogus', true);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testSetMyChannelConsentThrowsNoSuchEntityExceptionForAGuest(): void
    {
        $this->userContext->method('getUserId')->willReturn(null);

        $this->expectException(NoSuchEntityException::class);

        $this->makeManagement()->setMyChannelConsent('email', true);
    }
}
