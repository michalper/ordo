<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\Queue;

use Magento\Framework\Serialize\SerializerInterface;
use Ordo\Automation\Model\CustomerScoreManager;
use Ordo\Automation\Model\CustomerTagManager;
use Ordo\Automation\Model\Queue\SegmentBulkActionConsumer;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;

class SegmentBulkActionConsumerTest extends TestCase
{
    private CustomerTagManager $customerTagManager;
    private CustomerScoreManager $customerScoreManager;
    private SerializerInterface $serializer;
    private LoggerInterface $logger;

    protected function setUp(): void
    {
        $this->customerTagManager = $this->createMock(CustomerTagManager::class);
        $this->customerScoreManager = $this->createMock(CustomerScoreManager::class);
        $this->serializer = $this->createMock(SerializerInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
    }

    private function makeConsumer(): SegmentBulkActionConsumer
    {
        return new SegmentBulkActionConsumer(
            $this->customerTagManager,
            $this->customerScoreManager,
            $this->serializer,
            $this->logger
        );
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteAppliesAddTagToEveryCustomerAndLogsSummary(): void
    {
        $this->serializer->method('unserialize')->willReturn([
            'segment_id' => 5,
            'action_type' => 'add_tag',
            'params' => ['tag' => 'vip'],
            'customer_ids' => [1, 2, 3],
        ]);

        $this->customerTagManager->expects(self::exactly(3))->method('addTag')
            ->with(self::isInt(), 'vip');

        $this->logger->expects(self::never())->method('error');
        $this->logger->expects(self::once())->method('info')->with(
            'Ordo_Automation: applied bulk action "add_tag" to 3/3 segment members.'
        );

        $this->makeConsumer()->execute('raw-message');
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteAppliesAddPointsToEveryCustomer(): void
    {
        $this->serializer->method('unserialize')->willReturn([
            'action_type' => 'add_points',
            'params' => ['points' => 10],
            'customer_ids' => [1, 2],
        ]);

        $this->customerScoreManager->expects(self::exactly(2))->method('addPoints')
            ->with(self::isInt(), 10);

        $this->makeConsumer()->execute('raw-message');
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteLogsPerCustomerFailureAndContinuesTheLoop(): void
    {
        $this->serializer->method('unserialize')->willReturn([
            'action_type' => 'add_tag',
            'params' => ['tag' => 'vip'],
            'customer_ids' => [1, 2, 3],
        ]);

        $this->customerTagManager->method('addTag')
            ->willReturnCallback(function (int $customerId) {
                if ($customerId === 2) {
                    throw new \RuntimeException('db down');
                }
            });

        $this->logger->expects(self::once())->method('error');
        $this->logger->expects(self::once())->method('info')->with(
            'Ordo_Automation: applied bulk action "add_tag" to 2/3 segment members.'
        );

        $this->makeConsumer()->execute('raw-message');
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteLogsPerCustomerFailureForAnUnrecognizedActionType(): void
    {
        $this->serializer->method('unserialize')->willReturn([
            'action_type' => 'delete_everything',
            'params' => [],
            'customer_ids' => [1],
        ]);

        $this->customerTagManager->expects(self::never())->method('addTag');
        $this->customerScoreManager->expects(self::never())->method('addPoints');

        $this->logger->expects(self::once())->method('error')->with(
            self::stringContains('failed to apply bulk action "delete_everything" to customer #1')
        );
        $this->logger->expects(self::once())->method('info')->with(
            'Ordo_Automation: applied bulk action "delete_everything" to 0/1 segment members.'
        );

        $this->makeConsumer()->execute('raw-message');
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteLogsAndSkipsWhenActionTypeMissing(): void
    {
        $this->serializer->method('unserialize')->willReturn(['customer_ids' => [1]]);

        $this->customerTagManager->expects(self::never())->method('addTag');
        $this->logger->expects(self::once())->method('error');

        $this->makeConsumer()->execute('raw-message');
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteLogsAndSkipsWhenCustomerIdsMissing(): void
    {
        $this->serializer->method('unserialize')->willReturn(['action_type' => 'add_tag', 'customer_ids' => []]);

        $this->customerTagManager->expects(self::never())->method('addTag');
        $this->logger->expects(self::once())->method('error');

        $this->makeConsumer()->execute('raw-message');
    }
}
