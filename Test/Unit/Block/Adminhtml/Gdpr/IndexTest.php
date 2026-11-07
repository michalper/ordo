<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Block\Adminhtml\Gdpr;

use Magento\Backend\Block\Template\Context;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\ObjectManagerInterface;
use Magento\Framework\Registry;
use Magento\Framework\UrlInterface;
use Ordo\Automation\Block\Adminhtml\Gdpr\Index;
use Ordo\Automation\Model\ConsentChannel;
use Ordo\Automation\Model\ConsentManager;
use Ordo\Automation\Model\ConsentTopic;
use PHPUnit\Framework\TestCase;

/**
 * Same ObjectManager-singleton technique as Segment\BulkActionsTest - Backend\Block\Template's
 * own constructor falls back to it for jsonHelper/directoryHelper.
 */
class IndexTest extends TestCase
{
    private Registry $registry;
    private ConsentManager $consentManager;
    private UrlInterface $urlBuilder;
    private Index $block;

    protected function setUp(): void
    {
        $objectManager = $this->createStub(ObjectManagerInterface::class);
        $objectManager->method('get')->willReturn($this->createStub(\stdClass::class));
        ObjectManager::setInstance($objectManager);

        $this->registry = $this->createStub(Registry::class);
        $this->consentManager = $this->createStub(ConsentManager::class);
        $this->urlBuilder = $this->createStub(UrlInterface::class);

        $context = $this->createStub(Context::class);
        $context->method('getUrlBuilder')->willReturn($this->urlBuilder);

        $this->block = new Index($context, $this->registry, $this->consentManager);
    }

    protected function tearDown(): void
    {
        ObjectManager::setInstance($this->createStub(ObjectManagerInterface::class));
    }

    public function testGetCustomerIdReturnsZeroWhenNothingRegistered(): void
    {
        $this->registry->method('registry')->willReturn(null);

        self::assertSame(0, $this->block->getCustomerId());
    }

    public function testGetCustomerIdReturnsRegisteredValue(): void
    {
        $this->registry->method('registry')->willReturnMap([['ordo_gdpr_customer_id', 42]]);

        self::assertSame(42, $this->block->getCustomerId());
    }

    public function testGetCustomerEmailReturnsRegisteredValue(): void
    {
        $this->registry->method('registry')->willReturnMap([['ordo_gdpr_customer_email', 'jan@example.com']]);

        self::assertSame('jan@example.com', $this->block->getCustomerEmail());
    }

    public function testIsCustomerResolvedIsFalseWithoutACustomerId(): void
    {
        $this->registry->method('registry')->willReturn(null);

        self::assertFalse($this->block->isCustomerResolved());
    }

    public function testIsCustomerResolvedIsTrueWithACustomerId(): void
    {
        $this->registry->method('registry')->willReturnMap([['ordo_gdpr_customer_id', 42]]);

        self::assertTrue($this->block->isCustomerResolved());
    }

    public function testGetConsentStatesDefaultsToTrueForEveryChannelWhenNoneRecorded(): void
    {
        $this->registry->method('registry')->willReturnMap([['ordo_gdpr_customer_id', 42]]);
        $this->consentManager->method('getConsentStates')->willReturn([]);

        $states = $this->block->getConsentStates();

        self::assertTrue($states->isEmailConsented());
        self::assertTrue($states->isSmsConsented());
        self::assertTrue($states->isPushConsented());
        self::assertTrue($states->isWhatsAppConsented());
        self::assertTrue($states->isAdsConsented());
        self::assertSame(
            [
                ConsentChannel::Email->value => true,
                ConsentChannel::Sms->value => true,
                ConsentChannel::Push->value => true,
                ConsentChannel::WhatsApp->value => true,
                ConsentChannel::Ads->value => true,
            ],
            iterator_to_array($states)
        );
    }

    public function testGetConsentStatesReflectsRecordedOptOut(): void
    {
        $this->registry->method('registry')->willReturnMap([['ordo_gdpr_customer_id', 42]]);
        $this->consentManager->method('getConsentStates')->willReturn([ConsentChannel::Email->value => false]);

        $states = $this->block->getConsentStates();

        self::assertFalse($states->isEmailConsented());
        self::assertTrue($states->isSmsConsented());
    }

    public function testGetSearchFormActionBuildsIndexUrl(): void
    {
        $this->urlBuilder->method('getUrl')->willReturn('https://example.com/admin/ordo/gdpr/index/');

        self::assertSame('https://example.com/admin/ordo/gdpr/index/', $this->block->getSearchFormAction());
    }

    public function testGetSetConsentFormActionBuildsSetConsentUrl(): void
    {
        $this->urlBuilder->method('getUrl')->willReturn('https://example.com/admin/ordo/gdpr/setconsent/');

        self::assertSame('https://example.com/admin/ordo/gdpr/setconsent/', $this->block->getSetConsentFormAction());
    }

    public function testGetExportUrlIncludesCustomerId(): void
    {
        $this->registry->method('registry')->willReturnMap([['ordo_gdpr_customer_id', 42]]);
        $this->urlBuilder->method('getUrl')
            ->willReturnMap([['*/*/export', ['customer_id' => 42], 'https://example.com/admin/ordo/gdpr/export/customer_id/42/']]);

        self::assertSame(
            'https://example.com/admin/ordo/gdpr/export/customer_id/42/',
            $this->block->getExportUrl()
        );
    }

    public function testGetEraseFormActionBuildsEraseUrl(): void
    {
        $this->urlBuilder->method('getUrl')->willReturn('https://example.com/admin/ordo/gdpr/erase/');

        self::assertSame('https://example.com/admin/ordo/gdpr/erase/', $this->block->getEraseFormAction());
    }

    /**
     * Per ADR 0027, "no row" does not mean the same thing for every topic - AccountUpdates still
     * defaults to true (ADR 0026's original uniform default), Promotional now defaults to false.
     */
    public function testGetTopicConsentStatesUsesEachTopicsOwnDefaultAndCoversEveryMessageChannelButNotAds(): void
    {
        $this->registry->method('registry')->willReturnMap([['ordo_gdpr_customer_id', 42]]);
        $this->consentManager->method('getTopicConsentStates')->willReturn([]);

        $states = $this->block->getTopicConsentStates();

        self::assertSame(
            [
                ConsentChannel::Email->value,
                ConsentChannel::Sms->value,
                ConsentChannel::Push->value,
                ConsentChannel::WhatsApp->value,
            ],
            array_keys($states)
        );
        self::assertSame(
            [ConsentTopic::Promotional->value => false, ConsentTopic::AccountUpdates->value => true],
            $states[ConsentChannel::Email->value]
        );
    }

    public function testGetTopicConsentStatesReflectsRecordedOptIn(): void
    {
        $this->registry->method('registry')->willReturnMap([['ordo_gdpr_customer_id', 42]]);
        $this->consentManager->method('getTopicConsentStates')->willReturnCallback(
            fn (int $customerId, ConsentChannel $channel) => $channel === ConsentChannel::Email
                ? [ConsentTopic::Promotional->value => true]
                : []
        );

        $states = $this->block->getTopicConsentStates();

        self::assertTrue($states[ConsentChannel::Email->value][ConsentTopic::Promotional->value]);
        self::assertTrue($states[ConsentChannel::Email->value][ConsentTopic::AccountUpdates->value]);
        self::assertFalse($states[ConsentChannel::Sms->value][ConsentTopic::Promotional->value]);
    }

    public function testGetSetTopicConsentFormActionBuildsSetTopicConsentUrl(): void
    {
        $this->urlBuilder->method('getUrl')->willReturn('https://example.com/admin/ordo/gdpr/settopicconsent/');

        self::assertSame(
            'https://example.com/admin/ordo/gdpr/settopicconsent/',
            $this->block->getSetTopicConsentFormAction()
        );
    }

    public function testGetTopicLabelTranslatesKnownTopics(): void
    {
        self::assertSame('Promotional', $this->block->getTopicLabel(ConsentTopic::Promotional->value));
        self::assertSame('Account & Order Updates', $this->block->getTopicLabel(ConsentTopic::AccountUpdates->value));
    }

    public function testGetTopicLabelFallsBackToTheRawValueForAnUnknownTopic(): void
    {
        self::assertSame('mystery', $this->block->getTopicLabel('mystery'));
    }
}
