<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Integration;

use Magento\Framework\App\Bootstrap;
use Magento\Framework\ObjectManagerInterface;
use Ordo\Automation\Model\CustomerScoreManager;
use Ordo\Automation\Model\CustomerTagManager;
use Ordo\Automation\Model\Queue\SegmentBulkActionPublisher;
use Ordo\Automation\Model\ResourceModel\Segment as SegmentResource;
use Ordo\Automation\Model\ResourceModel\Segment\Condition as SegmentConditionResource;
use Ordo\Automation\Model\Segment\SegmentMemberResolver;
use Ordo\Automation\Model\SegmentConditionFactory;
use Ordo\Automation\Model\SegmentFactory;
use PHPUnit\Framework\TestCase;

/**
 * Proves the piece SegmentMemberResolverTest/SegmentBulkActionPublisherTest/
 * SegmentBulkActionConsumerTest each deliberately mock around: that a real segment's conditions
 * resolve to a real customer via SQL (not a stub), that publishing onto the real message queue
 * (etc/communication.xml, etc/queue*.xml) actually reaches SegmentBulkActionConsumer, and that
 * the consumer's per-customer action (add_points here) actually lands in the database — end to
 * end, no mocks, same shape as CampaignQueueWiringTest.
 *
 * This install has no RabbitMQ (DB queue driver only, see AGENTS.md) — running the real
 * `queue:consumers:start` CLI as a subprocess is the only way to exercise this wiring exactly
 * as production cron would.
 *
 * Run from the Magento root: vendor/bin/phpunit --bootstrap app/bootstrap.php
 * vendor/ordo/module-automation/Test/Integration/SegmentBulkActionQueueWiringTest.php
 */
class SegmentBulkActionQueueWiringTest extends TestCase
{
    private const CONSUMER_NAME = SegmentBulkActionPublisher::TOPIC;

    private static ObjectManagerInterface $objectManager;

    private CustomerTagManager $tagManager;
    private CustomerScoreManager $scoreManager;

    private ?int $segmentId = null;
    private ?int $customerId = null;
    private ?string $tag = null;

    public static function setUpBeforeClass(): void
    {
        require_once BP . '/app/bootstrap.php';
        $bootstrap = Bootstrap::create(BP, $_SERVER);
        self::$objectManager = $bootstrap->getObjectManager();
        self::$objectManager->get(\Magento\Framework\App\State::class)->setAreaCode('adminhtml');
        self::$objectManager->get(\Magento\Framework\Registry::class)->register('isSecureArea', true);
    }

    protected function setUp(): void
    {
        $this->tagManager = self::$objectManager->get(CustomerTagManager::class);
        $this->scoreManager = self::$objectManager->get(CustomerScoreManager::class);
    }

    protected function tearDown(): void
    {
        if ($this->tag !== null && $this->customerId !== null) {
            $this->tagManager->removeTag($this->customerId, $this->tag);
        }

        if ($this->segmentId !== null) {
            $segmentFactory = self::$objectManager->get(SegmentFactory::class);
            $segmentResource = self::$objectManager->get(SegmentResource::class);
            $segment = $segmentFactory->create();
            $segmentResource->load($segment, $this->segmentId);
            if ($segment->getEntityId()) {
                $segmentResource->delete($segment);
            }
            $this->segmentId = null;
        }

        if ($this->customerId !== null) {
            try {
                self::$objectManager->get(\Magento\Customer\Api\CustomerRepositoryInterface::class)
                    ->deleteById($this->customerId);
            } catch (\Throwable $e) {
                // Best-effort cleanup only.
            }
            $this->customerId = null;
        }
    }

    public function testResolvedSegmentMembersActuallyReceiveTheBulkActionThroughTheRealQueue(): void
    {
        $customerRepository = self::$objectManager->get(\Magento\Customer\Api\CustomerRepositoryInterface::class);
        $customerFactory = self::$objectManager->get(\Magento\Customer\Api\Data\CustomerInterfaceFactory::class);
        $storeManager = self::$objectManager->get(\Magento\Store\Model\StoreManagerInterface::class);

        $email = 'ordo-automation-segment-bulk-test-' . uniqid('', true) . '@example.test';
        $customer = $customerFactory->create();
        $customer->setEmail($email);
        $customer->setFirstname('Segment');
        $customer->setLastname('Bulk');
        $customer->setWebsiteId((int) $storeManager->getWebsite()->getId());
        $saved = $customerRepository->save($customer);
        $this->customerId = (int) $saved->getId();

        $this->tag = 'segment-bulk-wiring-' . uniqid('', true);
        $this->tagManager->addTag($this->customerId, $this->tag);

        $segmentFactory = self::$objectManager->get(SegmentFactory::class);
        $segmentResource = self::$objectManager->get(SegmentResource::class);
        $segment = $segmentFactory->create();
        $segment->setData([
            'name' => 'Segment bulk wiring test ' . uniqid('', true),
            'enabled' => true,
        ]);
        $segmentResource->save($segment);
        $this->segmentId = (int) $segment->getEntityId();

        $conditionFactory = self::$objectManager->get(SegmentConditionFactory::class);
        $conditionResource = self::$objectManager->get(SegmentConditionResource::class);
        $condition = $conditionFactory->create();
        $condition->setSegmentId($this->segmentId);
        $condition->setType('tag');
        $condition->setParamsJson(json_encode(['tag' => $this->tag]));
        $condition->setSortOrder(0);
        $conditionResource->save($condition);

        // Resolve membership for real (real SQL against ordo_customer_tag, not a mocked
        // CustomerTagManager) — this is the part SegmentMemberResolverTest can only stub.
        $resolver = self::$objectManager->get(SegmentMemberResolver::class);
        $customerIds = $resolver->getMatchingCustomerIds($this->segmentId);

        self::assertSame([$this->customerId], $customerIds, 'sanity check: resolver must find exactly our tagged customer');
        self::assertSame(0, $this->scoreManager->getScore($this->customerId), 'sanity check: score must be 0 before the bulk action runs');

        // Publish onto the real (DB-driver) queue — nothing has consumed it yet.
        self::$objectManager->get(SegmentBulkActionPublisher::class)
            ->publish($this->segmentId, 'add_points', ['points' => 15], $customerIds);

        // Run Magento's actual consumer runner as a subprocess, exactly like the
        // cron_consumers_runner config would in production (see AGENTS.md), to prove
        // queue.xml/queue_consumer.xml/queue_topology.xml/communication.xml actually resolve to
        // SegmentBulkActionConsumer::execute().
        $this->drainPendingMessages();

        self::assertSame(
            15,
            $this->scoreManager->getScore($this->customerId),
            'the full resolver -> publisher -> queue -> consumer -> CustomerScoreManager chain must have run'
        );
    }

    /**
     * Same FIFO-backlog reasoning as CampaignQueueWiringTest::drainPendingMessages() — count the
     * exact pending backlog on this topic right before running and pass that as --max-messages,
     * so the run neither exits early (backlog undercounted) nor hangs forever (this install's DB
     * queue driver has consumers_wait_for_messages=1, so it blocks once the counted backlog is
     * exhausted rather than exiting).
     */
    private function drainPendingMessages(): void
    {
        $resourceConnection = self::$objectManager->get(\Magento\Framework\App\ResourceConnection::class);
        $connection = $resourceConnection->getConnection();

        $pendingCount = (int) $connection->fetchOne(
            $connection->select()
                ->from(
                    ['qms' => $resourceConnection->getTableName('queue_message_status')],
                    ['COUNT(*)']
                )
                ->joinInner(
                    ['q' => $resourceConnection->getTableName('queue')],
                    'q.id = qms.queue_id',
                    []
                )
                ->where('q.name = ?', self::CONSUMER_NAME)
                // Magento\MysqlMq\Model\QueueManagement::MESSAGE_STATUS_NEW — not yet consumed.
                // That class isn't part of a stable public API, so the value is inlined rather
                // than depending on a Magento_MysqlMq class directly from this module's tests.
                ->where('qms.status = ?', 2)
        );

        self::assertGreaterThan(0, $pendingCount, 'sanity check: our own just-published message must be pending');

        $command = sprintf(
            '%s %s/bin/magento queue:consumers:start %s --max-messages=%d 2>&1',
            escapeshellarg(PHP_BINARY),
            escapeshellarg(rtrim(BP, '/')),
            escapeshellarg(self::CONSUMER_NAME),
            $pendingCount
        );

        exec($command, $output, $exitCode);

        self::assertSame(
            0,
            $exitCode,
            'queue:consumers:start must exit cleanly; output: ' . implode("\n", $output)
        );
    }
}
