<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Controller\Referral;

use Magento\Customer\Model\Session as CustomerSession;
use Magento\Customer\Model\Url as CustomerUrl;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\UrlInterface;
use Ordo\Automation\Controller\Referral\MyCode;
use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\ReferralManager;
use Ordo\Automation\Test\Unit\Controller\AbstractFrontendActionTestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;

class MyCodeTest extends AbstractFrontendActionTestCase
{
    private CustomerSession $customerSession;
    private CustomerUrl $customerUrl;
    private JsonFactory $resultJsonFactory;
    private Json $jsonResult;
    private ReferralManager $referralManager;
    private Config $config;
    private UrlInterface $url;

    protected function setUp(): void
    {
        $this->customerSession = $this->createStub(CustomerSession::class);
        $this->customerSession->method('getCustomerId')->willReturn(9);

        $this->customerUrl = $this->createStub(CustomerUrl::class);

        $this->jsonResult = $this->createMock(Json::class);
        $this->jsonResult->method('setData')->willReturnSelf();
        $this->resultJsonFactory = $this->createStub(JsonFactory::class);
        $this->resultJsonFactory->method('create')->willReturn($this->jsonResult);

        $this->referralManager = $this->createMock(ReferralManager::class);
        $this->config = $this->createStub(Config::class);
        $this->config->method('isReferralEnabled')->willReturn(true);
        $this->url = $this->createStub(UrlInterface::class);
        $this->url->method('getUrl')->willReturn('https://store.example/ordo/referral/track/ref/CODE1234/');
    }

    private function makeController(): MyCode
    {
        return new MyCode(
            $this->makeContext(),
            $this->customerSession,
            $this->customerUrl,
            $this->resultJsonFactory,
            $this->referralManager,
            $this->config,
            $this->url
        );
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteReturnsCodeAndShareUrl(): void
    {
        $this->referralManager->method('getOrCreateCode')->willReturn('CODE1234');

        $this->jsonResult->expects(self::once())->method('setData')->with([
            'ok' => true,
            'code' => 'CODE1234',
            'share_url' => 'https://store.example/ordo/referral/track/ref/CODE1234/',
        ]);

        $this->makeController()->execute();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteReturnsDisabledWhenReferralOff(): void
    {
        $this->config = $this->createStub(Config::class);
        $this->config->method('isReferralEnabled')->willReturn(false);

        $this->jsonResult->expects(self::once())->method('setData')
            ->with(['ok' => false, 'reason' => 'referral_disabled']);
        $this->referralManager->expects(self::never())->method('getOrCreateCode');

        $this->makeController()->execute();
    }
}
