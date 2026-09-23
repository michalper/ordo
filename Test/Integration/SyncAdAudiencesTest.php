<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Integration;

use Magento\Framework\App\Bootstrap;
use Magento\Framework\ObjectManagerInterface;
use Ordo\Automation\Api\AdAudience\SyncClientInterface;
use Ordo\Automation\Model\AdAudience;
use Ordo\Automation\Model\AdAudience\PiiHasher;
use Ordo\Automation\Model\AdAudience\SyncClientPool;
use Ordo\Automation\Model\AdAudienceFactory;
use Ordo\Automation\Model\CustomerTagManager;
use Ordo\Automation\Model\ResourceModel\AdAudience as AdAudienceResource;
use Ordo\Automation\Model\ResourceModel\Segment as SegmentResource;
use Ordo\Automation\Model\ResourceModel\Segment\Condition as SegmentConditionResource;
use Ordo\Automation\Model\SegmentConditionFactory;
use Ordo\Automation\Model\SegmentFactory;
use Ordo\Automation\Cron\SyncAdAudiences;
use PHPUnit\Framework\TestCase;

/**
 * Real DI/DB throughout (real Segment + SegmentCondition rows, real SegmentMemberResolver query,
 * real CustomerRepositoryInterface::getById() email resolution, real PiiHasher) — only
 * SyncClientPool's platform client is substituted with RecordingSyncClient below, since the real
 * implementations would otherwise make a live Google Ads/Meta API call. Same "override just the
 * one risky collaborator" pattern CampaignSendSmsActionTest already established.
 *
 * Run from the Magento root: vendor/bin/phpunit --bootstrap app/bootstrap.php
 * vendor/ordo/module-automation/Test/Integration/SyncAdAudiencesTest.php
 */
class SyncAdAudiencesTest extends TestCase
{
    private static ObjectManagerInterface $objectManager;

    private ?int $customerId = null;
    private ?int $segmentId = null;
    private ?int $adAudienceId = null;

    public static function setUpBeforeClass(): void
    {
        require_once BP . '/app/bootstrap.php';
        $bootstrap = Bootstrap::create(BP, $_SERVER);
        self::$objectManager = $bootstrap->getObjectManager();
        self::$objectManager->get(\Magento\Framework\App\State::class)->setAreaCode('frontend');
        self::$objectManager->get(\Magento\Framework\Registry::class)->register('isSecureArea', true);
    }

    protected function tearDown(): void
    {
        if ($this->adAudienceId !== null) {
            $adAudienceResource = self::$objectManager->get(AdAudienceResource::class);
            $adAudienceFactory = self::$objectManager->get(AdAudienceFactory::class);
            $adAudience = $adAudienceFactory->create();
            $adAudienceResource->load($adAudience, $this->adAudienceId);
            if ($adAudience->getEntityId()) {
                $adAudienceResource->delete($adAudience);
            }
        }
        if ($this->segmentId !== null) {
            $segmentResource = self::$objectManager->get(SegmentResource::class);
            $segmentFactory = self::$objectManager->get(SegmentFactory::class);
            $segment = $segmentFactory->create();
            $segmentResource->load($segment, $this->segmentId);
            if ($segment->getEntityId()) {
                $segmentResource->delete($segment);
            }
        }
        if ($this->customerId !== null) {
            try {
                self::$objectManager->get(\Magento\Customer\Api\CustomerRepositoryInterface::class)
                    ->deleteById($this->customerId);
            } catch (\Throwable $e) {
                // Best-effort cleanup only.
            }
        }
    }

    public function testExecuteResolvesRealSegmentMembersHashesAndSyncsThem(): void
    {
        $customerRepository = self::$objectManager->get(\Magento\Customer\Api\CustomerRepositoryInterface::class);
        $customerFactory = self::$objectManager->get(\Magento\Customer\Api\Data\CustomerInterfaceFactory::class);
        $storeManager = self::$objectManager->get(\Magento\Store\Model\StoreManagerInterface::class);

        $email = 'ordo-automation-ad-audience-test-' . uniqid('', true) . '@example.test';
        $customer = $customerFactory->create();
        $customer->setEmail($email);
        $customer->setFirstname('Ad');
        $customer->setLastname('Audience');
        $customer->setWebsiteId((int) $storeManager->getWebsite()->getId());
        $saved = $customerRepository->save($customer);
        $this->customerId = (int) $saved->getId();

        $tag = 'ad-audience-sync-' . uniqid('', true);
        self::$objectManager->get(CustomerTagManager::class)->addTag($this->customerId, $tag);

        $segmentFactory = self::$objectManager->get(SegmentFactory::class);
        $segmentResource = self::$objectManager->get(SegmentResource::class);
        $segment = $segmentFactory->create();
        $segment->setData(['name' => 'Ad audience sync test ' . uniqid('', true), 'enabled' => true]);
        $segmentResource->save($segment);
        $this->segmentId = (int) $segment->getEntityId();

        $conditionFactory = self::$objectManager->get(SegmentConditionFactory::class);
        $conditionResource = self::$objectManager->get(SegmentConditionResource::class);
        $condition = $conditionFactory->create();
        $condition->setSegmentId($this->segmentId);
        $condition->setType('tag');
        $condition->setParamsJson(json_encode(['tag' => $tag]));
        $condition->setSortOrder(0);
        $conditionResource->save($condition);

        $adAudienceFactory = self::$objectManager->get(AdAudienceFactory::class);
        $adAudienceResource = self::$objectManager->get(AdAudienceResource::class);
        $adAudience = $adAudienceFactory->create();
        $adAudience->setName('Ad audience sync test');
        $adAudience->setSegmentId($this->segmentId);
        $adAudience->setPlatform(AdAudience::PLATFORM_GOOGLE_ADS);
        $adAudience->setExternalAudienceId('customers/123/userLists/456');
        $adAudience->setEnabled(true);
        $adAudienceResource->save($adAudience);
        $this->adAudienceId = (int) $adAudience->getEntityId();

        $recordingClient = new RecordingSyncClient();
        $syncClientPool = new SyncClientPool(['google_ads' => $recordingClient]);

        /** @var SyncAdAudiences $cron */
        $cron = self::$objectManager->create(SyncAdAudiences::class, [
            'syncClientPool' => $syncClientPool,
        ]);
        $cron->execute();

        $expectedHash = (new PiiHasher())->hashEmail($email);
        self::assertSame('customers/123/userLists/456', $recordingClient->calls[0]['externalAudienceId'] ?? null);
        self::assertSame([$expectedHash], $recordingClient->calls[0]['hashedEmails'] ?? null);

        $adAudienceResource->load($adAudience, $this->adAudienceId);
        self::assertSame(AdAudience::STATUS_SUCCESS, $adAudience->getLastSyncStatus());
        self::assertNotNull($adAudience->getLastSyncedAt());
    }
}

/**
 * Records (externalAudienceId, hashedEmails) instead of calling a real ad platform - test-only
 * helper, same role RecordingTwilioSmsSender plays in CampaignSendSmsActionTest.
 */
class RecordingSyncClient implements SyncClientInterface
{
    /** @var array<int, array{externalAudienceId: ?string, hashedEmails: array<int, string>}> */
    public array $calls = [];

    public function sync(?string $externalAudienceId, array $hashedEmails): ?string
    {
        $this->calls[] = ['externalAudienceId' => $externalAudienceId, 'hashedEmails' => $hashedEmails];

        return null;
    }
}
