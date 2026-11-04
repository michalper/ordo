<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Controller\Adminhtml\Tag;

use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Ordo\Automation\Controller\Adminhtml\Tag\Search;
use Ordo\Automation\Model\CustomerTagManager;
use Ordo\Automation\Test\Unit\Controller\AbstractAdminActionTestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;

class SearchTest extends AbstractAdminActionTestCase
{
    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteReturnsEmptyItemsForBlankTerm(): void
    {
        $context = $this->makeContext();
        $this->request->method('getParam')->willReturnMap([['term', '', '  ']]);

        $result = $this->createMock(Json::class);
        $result->expects(self::once())->method('setData')->with(['items' => []])->willReturnSelf();

        $resultJsonFactory = $this->createStub(JsonFactory::class);
        $resultJsonFactory->method('create')->willReturn($result);

        $customerTagManager = $this->createMock(CustomerTagManager::class);
        $customerTagManager->expects(self::never())->method('searchTags');

        $controller = new Search($context, $resultJsonFactory, $customerTagManager);

        $controller->execute();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteMapsMatchingTagsToJsonItems(): void
    {
        $context = $this->makeContext();
        $this->request->method('getParam')->willReturnMap([['term', '', 'vi']]);

        $customerTagManager = $this->createStub(CustomerTagManager::class);
        $customerTagManager->method('searchTags')->willReturn(['vip', 'vip-gold']);

        $result = $this->createMock(Json::class);
        $result->expects(self::once())->method('setData')->with([
            'items' => [
                ['tag' => 'vip'],
                ['tag' => 'vip-gold'],
            ],
        ])->willReturnSelf();

        $resultJsonFactory = $this->createStub(JsonFactory::class);
        $resultJsonFactory->method('create')->willReturn($result);

        $controller = new Search($context, $resultJsonFactory, $customerTagManager);

        $controller->execute();
    }
}
