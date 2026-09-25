<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Controller\WellKnown;

use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Ordo\Automation\Controller\WellKnown\AiPluginManifest;
use Ordo\Automation\Helper\Config;
use Ordo\Automation\Test\Unit\Controller\AbstractFrontendActionTestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;

class AiPluginManifestTest extends AbstractFrontendActionTestCase
{
    private JsonFactory $resultJsonFactory;
    private StoreManagerInterface $storeManager;
    private Config $config;
    private Json $jsonResult;

    protected function setUp(): void
    {
        $this->jsonResult = $this->createMock(Json::class);
        $this->resultJsonFactory = $this->createStub(JsonFactory::class);
        $this->resultJsonFactory->method('create')->willReturn($this->jsonResult);

        $store = $this->createStub(Store::class);
        $store->method('getId')->willReturn(1);
        $store->method('getBaseUrl')->willReturn('https://example.test/');
        $this->storeManager = $this->createStub(StoreManagerInterface::class);
        $this->storeManager->method('getStore')->willReturn($store);

        $this->config = $this->createStub(Config::class);
    }

    private function makeController(): AiPluginManifest
    {
        return new AiPluginManifest($this->makeContext(), $this->resultJsonFactory, $this->storeManager, $this->config);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteReturns404WhenAiAgentDisabled(): void
    {
        $this->config->method('isAiAgentEnabled')->willReturn(false);

        $this->jsonResult->expects(self::once())->method('setHttpResponseCode')->with(404);
        $this->jsonResult->expects(self::never())->method('setData');

        self::assertSame($this->jsonResult, $this->makeController()->execute());
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteReturnsManifestPointingAtTheAiAgentFeedWhenEnabled(): void
    {
        $this->config->method('isAiAgentEnabled')->willReturn(true);
        $this->config->method('getAiAgentName')->willReturn('My Store');
        $this->config->method('getAiAgentDescription')->willReturn('A great store');
        $this->config->method('getAiAgentContactEmail')->willReturn('shop@example.test');

        $this->jsonResult->expects(self::never())->method('setHttpResponseCode');
        $this->jsonResult->expects(self::once())->method('setData')->with(self::callback(function (array $data) {
            self::assertSame('v1', $data['schema_version']);
            self::assertSame('My Store', $data['name_for_human']);
            self::assertSame('A great store', $data['description_for_model']);
            self::assertSame(['type' => 'none'], $data['auth']);
            self::assertSame('https://example.test/ordo/productfeed/aiagent', $data['api']['url']);
            self::assertSame('shop@example.test', $data['contact_email']);
            return true;
        }));

        self::assertSame($this->jsonResult, $this->makeController()->execute());
    }
}
