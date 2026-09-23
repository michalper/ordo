<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Integration;

use Magento\Framework\App\Bootstrap;
use Magento\Framework\ObjectManagerInterface;
use Ordo\Automation\Cron\RetryFailedPushSends;
use Ordo\Automation\Model\Push\PushSendRetryQueue;
use Ordo\Automation\Model\Push\PushSender;
use Ordo\Automation\Model\Push\PushSubscriptionSender;
use Ordo\Automation\Model\PushSubscription;
use Ordo\Automation\Model\PushSubscriptionFactory;
use Ordo\Automation\Model\ResourceModel\PushSendRetry\CollectionFactory as PushSendRetryCollectionFactory;
use Ordo\Automation\Model\ResourceModel\PushSubscription as PushSubscriptionResource;
use PHPUnit\Framework\TestCase;

/**
 * Closes SCENARIOS.md #11's last open row: RetryFailedPushSendsTest (Test/Unit) and
 * PushSendRetryQueueTest/PushSubscriptionSenderTest already cover the branching logic with every
 * collaborator mocked, but never the real thing this cron actually depends on: a real atomic
 * conditional UPDATE (ResourceModel\PushSendRetry::claim()) against a real database, and a real
 * end-to-end composition of cron -> resource models -> PushSendRetryQueue's own backoff math ->
 * dead-letter exclusion. Same "override just the one risky external call" posture as
 * CampaignSendSmsActionTest - here the risky collaborator is Model\Push\PushSender's own real
 * HTTP POST to a push service (FCM/Mozilla autopush/...) this environment has none of; everything
 * around it (the cron, both resource models, the retry queue, the subscription row) is real DI
 * against the real database.
 *
 * Run from the Magento root: vendor/bin/phpunit --bootstrap app/bootstrap.php
 * vendor/ordo/module-automation/Test/Integration/RetryFailedPushSendsTest.php
 */
class RetryFailedPushSendsTest extends TestCase
{
    private static ObjectManagerInterface $objectManager;

    private ?int $subscriptionId = null;

    public static function setUpBeforeClass(): void
    {
        require_once BP . '/app/bootstrap.php';
        $bootstrap = Bootstrap::create(BP, $_SERVER);
        self::$objectManager = $bootstrap->getObjectManager();
        self::$objectManager->get(\Magento\Framework\App\State::class)->setAreaCode('adminhtml');
    }

    protected function tearDown(): void
    {
        if ($this->subscriptionId !== null) {
            try {
                $subscription = self::$objectManager->get(PushSubscriptionFactory::class)->create();
                self::$objectManager->get(PushSubscriptionResource::class)->load($subscription, $this->subscriptionId);
                if ($subscription->getId()) {
                    self::$objectManager->get(PushSubscriptionResource::class)->delete($subscription);
                }
            } catch (\Throwable $e) {
                // Best-effort cleanup only.
            }
            $this->subscriptionId = null;
        }
    }

    public function testSuccessfulRetryDeletesTheRetryRow(): void
    {
        $subscription = $this->createRealSubscription();
        $fakeSender = self::$objectManager->create(FakePushSender::class);
        $fakeSender->shouldSucceed = true;

        $this->enqueueDueRetry($subscription);

        $this->runCronWithFakeSender($fakeSender);

        self::assertSame(1, $fakeSender->callCount);
        self::assertNull($this->findRetryRow($subscription));
    }

    public function testFailedRetryAppliesBackoffThenBecomesADeadLetterAfterMaxAttempts(): void
    {
        $subscription = $this->createRealSubscription();
        $fakeSender = self::$objectManager->create(FakePushSender::class);
        $fakeSender->shouldSucceed = false;

        $this->enqueueDueRetry($subscription);

        // Drive it through every attempt up to MAX_ATTEMPTS, backdating next_retry_at back into
        // the past between runs - real ResourceModel\PushSendRetry::claim() (atomic conditional
        // UPDATE) and Collection::addDueFilter() decide what's due each time, not this test.
        for ($i = 1; $i <= RetryFailedPushSends::MAX_ATTEMPTS; $i++) {
            $this->backdateRetryRowToPast($subscription);
            $this->runCronWithFakeSender($fakeSender);
        }

        self::assertSame(RetryFailedPushSends::MAX_ATTEMPTS, $fakeSender->callCount);

        $retry = $this->findRetryRow($subscription);
        self::assertNotNull($retry, 'The exhausted row must remain as a dead letter, not be deleted.');
        self::assertSame(RetryFailedPushSends::MAX_ATTEMPTS, (int) $retry->getAttempts());

        // A dead letter is permanently excluded from future selection, even once its own
        // next_retry_at is backdated again - Collection::addDueFilter()'s own attempts < MAX
        // condition is what actually stops it, not merely the timing.
        $this->backdateRetryRowToPast($subscription);
        $collection = self::$objectManager->create(PushSendRetryCollectionFactory::class)->create();
        $collection->addDueFilter(date('Y-m-d H:i:s'), RetryFailedPushSends::MAX_ATTEMPTS);
        self::assertCount(0, $collection, 'A dead-lettered row must never be selected as due again.');
    }

    private function createRealSubscription(): PushSubscription
    {
        $endpoint = 'https://push.example.test/ordo-integration-test/' . uniqid('', true);

        /** @var PushSubscription $subscription */
        $subscription = self::$objectManager->get(PushSubscriptionFactory::class)->create();
        $subscription->setEndpoint($endpoint);
        $subscription->setData('endpoint_hash', hash('sha256', $endpoint));
        $subscription->setP256dhKey('test-p256dh-key');
        $subscription->setAuthKey('test-auth-key');

        self::$objectManager->get(PushSubscriptionResource::class)->save($subscription);
        $this->subscriptionId = (int) $subscription->getId();

        return $subscription;
    }

    private function enqueueDueRetry(PushSubscription $subscription): void
    {
        $retryQueue = self::$objectManager->get(PushSendRetryQueue::class);
        $retryQueue->enqueue(
            (int) $subscription->getEntityId(),
            1,
            null,
            null,
            '{"title":"Test","body":"Integration test payload"}',
            new \RuntimeException('seed error for integration test')
        );

        // enqueue() schedules the first real attempt 5 minutes from now (real production
        // backoff) - backdated here so it's already due, same idea as MFTF's own
        // CronScheduleHelper backdate methods, just at the PHP/resource-model level instead of
        // raw SQL.
        $this->backdateRetryRowToPast($subscription);
    }

    private function backdateRetryRowToPast(PushSubscription $subscription): void
    {
        $retry = $this->findRetryRow($subscription);
        self::assertNotNull($retry, 'Expected a retry row to exist for this subscription.');
        $retry->setNextRetryAt(date('Y-m-d H:i:s', strtotime('-1 minute')));
        self::$objectManager->get(\Ordo\Automation\Model\ResourceModel\PushSendRetry::class)->save($retry);
    }

    private function findRetryRow(PushSubscription $subscription): ?\Ordo\Automation\Model\PushSendRetry
    {
        $collection = self::$objectManager->create(PushSendRetryCollectionFactory::class)->create();
        $collection->addFieldToFilter('subscription_id', (int) $subscription->getEntityId());

        /** @var \Ordo\Automation\Model\PushSendRetry|null $retry */
        $retry = $collection->getFirstItem();

        return $retry !== null && $retry->getId() ? $retry : null;
    }

    private function runCronWithFakeSender(FakePushSender $fakeSender): void
    {
        $subscriptionSender = self::$objectManager->create(PushSubscriptionSender::class, [
            'pushSender' => $fakeSender,
        ]);

        /** @var RetryFailedPushSends $cron */
        $cron = self::$objectManager->create(RetryFailedPushSends::class, [
            'pushSubscriptionSender' => $subscriptionSender,
        ]);

        $cron->execute();
    }
}

/**
 * Stands in for a real push service - never makes a real HTTP call. Extends the real PushSender
 * (real Curl/Config/VapidTokenBuilder/WebPushCrypto/PushEndpointValidator/OutboundRateLimiter
 * dependencies from DI, all harmless to construct) and overrides only send() itself, the one
 * method that would otherwise make the real outbound HTTP request.
 */
class FakePushSender extends PushSender
{
    public bool $shouldSucceed = true;

    public int $callCount = 0;

    public function send(PushSubscription $subscription, string $payloadJson): void
    {
        $this->callCount++;

        if (!$this->shouldSucceed) {
            throw new \RuntimeException('Simulated push service failure for integration test');
        }
    }
}
