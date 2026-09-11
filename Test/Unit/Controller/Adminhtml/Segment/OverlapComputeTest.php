<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Controller\Adminhtml\Segment;

use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Ordo\Automation\Controller\Adminhtml\Segment\OverlapCompute;
use Ordo\Automation\Model\Segment\SegmentMemberResolver;
use Ordo\Automation\Test\Unit\Controller\AbstractAdminActionTestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;

class OverlapComputeTest extends AbstractAdminActionTestCase
{
    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteReturnsSizesIntersectionAndUniqueCounts(): void
    {
        $context = $this->makeContext();
        $this->request->method('getParam')->willReturnMap([
            ['segment_id_a', null, '1'],
            ['segment_id_b', null, '2'],
        ]);

        $result = $this->createMock(Json::class);
        $result->expects(self::once())->method('setData')->with([
            'size_a' => 3,
            'size_b' => 3,
            'intersection' => 2,
            'unique_a' => 1,
            'unique_b' => 1,
        ])->willReturnSelf();

        $resultJsonFactory = $this->createStub(JsonFactory::class);
        $resultJsonFactory->method('create')->willReturn($result);

        $segmentMemberResolver = $this->createStub(SegmentMemberResolver::class);
        $segmentMemberResolver->method('getMatchingCustomerIds')->willReturnCallback(
            fn (int $segmentId) => match ($segmentId) {
                1 => [1, 2, 3],
                2 => [2, 3, 4],
                default => [],
            }
        );

        $controller = new OverlapCompute($context, $resultJsonFactory, $segmentMemberResolver);

        $controller->execute();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteReturnsAnErrorForTheSameSegmentTwiceWithoutResolving(): void
    {
        $context = $this->makeContext();
        $this->request->method('getParam')->willReturnMap([
            ['segment_id_a', null, '5'],
            ['segment_id_b', null, '5'],
        ]);

        $result = $this->createMock(Json::class);
        $result->expects(self::once())->method('setData')->with([
            'error' => 'Pick two different segments.',
        ])->willReturnSelf();

        $resultJsonFactory = $this->createStub(JsonFactory::class);
        $resultJsonFactory->method('create')->willReturn($result);

        $segmentMemberResolver = $this->createMock(SegmentMemberResolver::class);
        $segmentMemberResolver->expects(self::never())->method('getMatchingCustomerIds');

        $controller = new OverlapCompute($context, $resultJsonFactory, $segmentMemberResolver);

        $controller->execute();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteReturnsAnErrorForMissingSegmentIdsWithoutResolving(): void
    {
        $context = $this->makeContext();
        $this->request->method('getParam')->willReturnMap([
            ['segment_id_a', null, '0'],
            ['segment_id_b', null, '7'],
        ]);

        $result = $this->createMock(Json::class);
        $result->expects(self::once())->method('setData')->with([
            'error' => 'Pick two different segments.',
        ])->willReturnSelf();

        $resultJsonFactory = $this->createStub(JsonFactory::class);
        $resultJsonFactory->method('create')->willReturn($result);

        $segmentMemberResolver = $this->createMock(SegmentMemberResolver::class);
        $segmentMemberResolver->expects(self::never())->method('getMatchingCustomerIds');

        $controller = new OverlapCompute($context, $resultJsonFactory, $segmentMemberResolver);

        $controller->execute();
    }
}
