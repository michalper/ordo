<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Controller\Adminhtml\Ai;

use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Ordo\Automation\Controller\Adminhtml\Ai\TestConnection;
use Ordo\Automation\Model\Ai\OllamaClient;
use Ordo\Automation\Test\Unit\Controller\AbstractAdminActionTestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;

class TestConnectionTest extends AbstractAdminActionTestCase
{
    private OllamaClient&\PHPUnit\Framework\MockObject\MockObject $ollamaClient;

    /** @var array<string, mixed> */
    private array $lastResultData = [];

    protected function setUp(): void
    {
        $this->ollamaClient = $this->createMock(OllamaClient::class);
    }

    private function makeController(\Magento\Backend\App\Action\Context $context): TestConnection
    {
        $result = $this->createStub(Json::class);
        $result->method('setData')->willReturnCallback(function (array $data) use ($result) {
            $this->lastResultData = $data;
            return $result;
        });
        $resultJsonFactory = $this->createStub(JsonFactory::class);
        $resultJsonFactory->method('create')->willReturn($result);

        return new TestConnection($context, $resultJsonFactory, $this->ollamaClient);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testSuccessfulResponseReturnsOkTrue(): void
    {
        $this->ollamaClient->expects(self::once())->method('generate')->willReturn('OK');

        $controller = $this->makeController($this->makeContext());
        $controller->execute();

        self::assertTrue($this->lastResultData['ok']);
        self::assertStringContainsString('OK', $this->lastResultData['message']);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testNullResponseReturnsOkFalse(): void
    {
        $this->ollamaClient->expects(self::once())->method('generate')->willReturn(null);

        $controller = $this->makeController($this->makeContext());
        $controller->execute();

        self::assertFalse($this->lastResultData['ok']);
        self::assertNotEmpty($this->lastResultData['message']);
    }
}
