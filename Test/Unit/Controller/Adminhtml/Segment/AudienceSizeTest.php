<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Controller\Adminhtml\Segment;

use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Ordo\Automation\Controller\Adminhtml\Segment\AudienceSize;
use Ordo\Automation\Model\Segment\SegmentMemberResolver;
use Ordo\Automation\Test\Unit\Controller\AbstractAdminActionTestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;

class AudienceSizeTest extends AbstractAdminActionTestCase
{
    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteReturnsZeroForMissingSegmentIdWithoutResolving(): void
    {
        $context = $this->makeContext();
        $this->request->method('getParam')->willReturnMap([['segment_id', null, '0']]);

        $result = $this->createMock(Json::class);
        $result->expects(self::once())->method('setData')->with(['count' => 0])->willReturnSelf();

        $resultJsonFactory = $this->createStub(JsonFactory::class);
        $resultJsonFactory->method('create')->willReturn($result);

        $segmentMemberResolver = $this->createMock(SegmentMemberResolver::class);
        $segmentMemberResolver->expects(self::never())->method('getMatchingCustomerIds');

        $controller = new AudienceSize($context, $resultJsonFactory, $segmentMemberResolver);

        $controller->execute();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteReturnsMatchingCustomerCount(): void
    {
        $context = $this->makeContext();
        $this->request->method('getParam')->willReturnMap([['segment_id', null, '7']]);

        $result = $this->createMock(Json::class);
        $result->expects(self::once())->method('setData')->with(['count' => 3])->willReturnSelf();

        $resultJsonFactory = $this->createStub(JsonFactory::class);
        $resultJsonFactory->method('create')->willReturn($result);

        $segmentMemberResolver = $this->createStub(SegmentMemberResolver::class);
        $segmentMemberResolver->method('getMatchingCustomerIds')->willReturnCallback(
            fn (int $segmentId) => $segmentId === 7 ? [1, 2, 3] : []
        );

        $controller = new AudienceSize($context, $resultJsonFactory, $segmentMemberResolver);

        $controller->execute();
    }
}
