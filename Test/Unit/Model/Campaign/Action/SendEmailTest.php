<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\Campaign\Action;

use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Mail\Template\TransportBuilder;
use Magento\Framework\Mail\TransportInterface;
use Magento\Framework\Translate\Inline\StateInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use Ordo\Automation\Model\Campaign\Action\SendEmail;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;

class SendEmailTest extends TestCase
{
    private CustomerRepositoryInterface $customerRepository;
    private TransportBuilder $transportBuilder;
    private StoreManagerInterface $storeManager;
    private StateInterface $inlineTranslation;
    private LoggerInterface $logger;
    private StoreInterface $store;

    protected function setUp(): void
    {
        $this->customerRepository = $this->createMock(CustomerRepositoryInterface::class);
        $this->transportBuilder = $this->createMock(TransportBuilder::class);
        $this->storeManager = $this->createStub(StoreManagerInterface::class);
        $this->inlineTranslation = $this->createMock(StateInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->store = $this->createStub(StoreInterface::class);
        $this->store->method('getId')->willReturn(1);
        $this->storeManager->method('getStore')->willReturn($this->store);
    }

    private function makeAction(): SendEmail
    {
        return new SendEmail(
            $this->customerRepository,
            $this->transportBuilder,
            $this->storeManager,
            $this->inlineTranslation,
            $this->logger
        );
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteSendsTransport(): void
    {
        $customer = $this->createStub(CustomerInterface::class);
        $customer->method('getFirstname')->willReturn('Jan');
        $customer->method('getEmail')->willReturn('jan@example.com');
        $this->customerRepository->method('getById')->willReturnMap([[42, $customer]]);

        $this->transportBuilder->method('setTemplateIdentifier')->willReturnSelf();
        $this->transportBuilder->method('setTemplateOptions')->willReturnSelf();
        $this->transportBuilder->method('setTemplateVars')->willReturnSelf();
        $this->transportBuilder->method('setFromByScope')->willReturnSelf();
        $this->transportBuilder->method('addTo')->willReturnSelf();

        $transport = $this->createMock(TransportInterface::class);
        $transport->expects(self::once())->method('sendMessage');
        $this->transportBuilder->method('getTransport')->willReturn($transport);

        $this->inlineTranslation->expects(self::once())->method('suspend');
        $this->inlineTranslation->expects(self::once())->method('resume');

        $context = ['customer_id' => 42];
        $this->makeAction()->execute($context, ['template' => 'ordo_campaign_generic']);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testRecommendedProductsHtmlSurvivesIntoTemplateVars(): void
    {
        // recommended_products_html is a rendered HTML *string*, which is_scalar() considers
        // scalar — so it must pass through SendEmail's array_filter(..., is_scalar(...)) call
        // untouched, without any code change to SendEmail itself. This is a regression test for
        // that integration point.
        $customer = $this->createStub(CustomerInterface::class);
        $customer->method('getFirstname')->willReturn('Jan');
        $customer->method('getEmail')->willReturn('jan@example.com');
        $this->customerRepository->method('getById')->willReturn($customer);

        $this->transportBuilder->method('setTemplateIdentifier')->willReturnSelf();
        $this->transportBuilder->method('setTemplateOptions')->willReturnSelf();
        $this->transportBuilder->method('setFromByScope')->willReturnSelf();
        $this->transportBuilder->method('addTo')->willReturnSelf();

        $capturedVars = null;
        $this->transportBuilder->method('setTemplateVars')->willReturnCallback(
            function (array $vars) use (&$capturedVars) {
                $capturedVars = $vars;
                return $this->transportBuilder;
            }
        );

        $transport = $this->createStub(TransportInterface::class);
        $this->transportBuilder->method('getTransport')->willReturn($transport);

        $context = ['customer_id' => 42, 'recommended_products_html' => '<table>...</table>'];
        $this->makeAction()->execute($context, ['template' => 'ordo_campaign_generic']);

        self::assertIsArray($capturedVars);
        self::assertSame('<table>...</table>', $capturedVars['recommended_products_html']);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteLogsErrorWhenCustomerIdMissing(): void
    {
        $this->customerRepository->expects(self::never())->method('getById');
        $this->logger->expects(self::once())->method('error');

        $context = [];
        $this->makeAction()->execute($context, ['template' => 'ordo_campaign_generic']);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteLogsErrorWhenTemplateMissing(): void
    {
        $this->logger->expects(self::once())->method('error');

        $context = ['customer_id' => 42];
        $this->makeAction()->execute($context, []);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteReturnsSilentlyWhenCustomerNotFound(): void
    {
        $this->customerRepository->method('getById')->willThrowException(new LocalizedException(__('no such customer')));
        $this->transportBuilder->expects(self::never())->method('getTransport');

        $context = ['customer_id' => 42];
        $this->makeAction()->execute($context, ['template' => 'ordo_campaign_generic']);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteLogsErrorWhenTransportThrows(): void
    {
        $customer = $this->createStub(CustomerInterface::class);
        $customer->method('getFirstname')->willReturn('Jan');
        $customer->method('getEmail')->willReturn('jan@example.com');
        $this->customerRepository->method('getById')->willReturn($customer);

        $this->transportBuilder->method('setTemplateIdentifier')->willReturnSelf();
        $this->transportBuilder->method('setTemplateOptions')->willReturnSelf();
        $this->transportBuilder->method('setTemplateVars')->willReturnSelf();
        $this->transportBuilder->method('setFromByScope')->willReturnSelf();
        $this->transportBuilder->method('addTo')->willReturnSelf();
        $this->transportBuilder->method('getTransport')->willThrowException(new \RuntimeException('smtp down'));

        $this->inlineTranslation->expects(self::once())->method('resume');
        $this->logger->expects(self::once())->method('error');

        $context = ['customer_id' => 42];
        $this->makeAction()->execute($context, ['template' => 'ordo_campaign_generic']);
    }
}
