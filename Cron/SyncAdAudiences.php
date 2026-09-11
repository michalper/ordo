<?php
declare(strict_types=1);

namespace Ordo\Automation\Cron;

use Ordo\Automation\Api\AdAudience\SyncClientInterface;
use Ordo\Automation\Model\AdAudience;
use Ordo\Automation\Model\AdAudience\PiiHasher;
use Ordo\Automation\Model\AdAudience\SyncClientPool;
use Ordo\Automation\Model\ConsentChannel;
use Ordo\Automation\Model\ConsentManager;
use Ordo\Automation\Model\Cron\CronRunLogger;
use Ordo\Automation\Model\CustomerMapBuilder;
use Ordo\Automation\Model\ResourceModel\AdAudience as AdAudienceResource;
use Ordo\Automation\Model\ResourceModel\AdAudience\CollectionFactory as AdAudienceCollectionFactory;
use Ordo\Automation\Model\Segment\SegmentMemberResolver;
use Psr\Log\LoggerInterface;

/**
 * For every enabled ordo_ad_audience row: resolve its segment's CURRENT matching customers
 * (Model\Segment\SegmentMemberResolver — the same reusable resolver In Segment/segment bulk
 * actions already use), hash their emails (PiiHasher), and hand the list to the configured
 * platform's SyncClientInterface. One row's failure is caught and recorded, never allowed to
 * stop the rest of the run — same isolation discipline as every other per-row cron in this
 * module (e.g. Cron\SendReorderReminders).
 */
class SyncAdAudiences
{
    public function __construct(
        private readonly AdAudienceCollectionFactory $adAudienceCollectionFactory,
        private readonly AdAudienceResource $adAudienceResource,
        private readonly SegmentMemberResolver $segmentMemberResolver,
        private readonly CustomerMapBuilder $customerMapBuilder,
        private readonly PiiHasher $piiHasher,
        private readonly SyncClientPool $syncClientPool,
        private readonly ConsentManager $consentManager,
        private readonly CronRunLogger $cronRunLogger,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(): void
    {
        $collection = $this->adAudienceCollectionFactory->create();
        $collection->addEnabledFilter();

        $synced = 0;
        $failed = 0;

        foreach ($collection as $adAudience) {
            /** @var AdAudience $adAudience */
            try {
                $this->syncOne($adAudience);
                $synced++;
            } catch (\Throwable $e) {
                $failed++;
                $this->logger->error(sprintf(
                    'Ordo_Automation: ad audience sync failed for ordo_ad_audience #%d (%s): %s',
                    (int) $adAudience->getEntityId(),
                    $adAudience->getPlatform(),
                    $e->getMessage()
                ));
                $adAudience->setLastSyncedAt(date('Y-m-d H:i:s'));
                $adAudience->setLastSyncStatus(AdAudience::STATUS_ERROR);
                $adAudience->setLastSyncMessage(substr($e->getMessage(), 0, 255));
                $this->adAudienceResource->save($adAudience);
            }
        }

        $this->cronRunLogger->logSummary(sprintf('synced %d and failed %d ad audiences', $synced, $failed));
    }

    private function syncOne(AdAudience $adAudience): void
    {
        $client = $this->syncClientPool->get($adAudience->getPlatform());
        if (!$client instanceof SyncClientInterface) {
            throw new \RuntimeException(
                sprintf('No sync client registered for platform "%s".', $adAudience->getPlatform())
            );
        }

        $customerIds = $this->segmentMemberResolver->getMatchingCustomerIds($adAudience->getSegmentId());
        // One batched customer_entity lookup for the whole segment instead of one EAV load per
        // customer_id - found via a performance audit, real impact at a few thousand members.
        $customerMap = $this->customerMapBuilder->build($customerIds);

        // A customer who withdrew ad-sharing consent (ConsentChannel::Ads) must never
        // have their email uploaded to a third-party ad platform here, regardless of whether they
        // still match the segment - this is data leaving the store entirely, not just a message
        // being sent, so it's checked per customer before hashing/upload, same as every other
        // channel's send action checks hasConsent() before sending.
        // One query for the whole batch instead of one hasConsent() call per customer inside the
        // loop below - found via a performance audit, same reasoning as
        // CreditLimitCalculator::getUsedCreditForCustomers().
        $consentByCustomer = $this->consentManager->hasConsentForCustomers($customerIds, ConsentChannel::Ads);

        $emails = [];
        foreach ($customerMap as $customerId => $customer) {
            if (!($consentByCustomer[$customerId] ?? true)) {
                continue;
            }

            $email = (string) $customer->getEmail();
            if ($email !== '') {
                $emails[] = $email;
            }
        }

        $hashedEmails = $this->piiHasher->hashEmails($emails);
        $createdAudienceId = $client->sync($adAudience->getExternalAudienceId(), $hashedEmails);

        if ($createdAudienceId !== null) {
            $adAudience->setExternalAudienceId($createdAudienceId);
        }

        $adAudience->setLastSyncedAt(date('Y-m-d H:i:s'));
        $adAudience->setLastSyncStatus(AdAudience::STATUS_SUCCESS);
        $adAudience->setLastSyncMessage(sprintf('%d member(s) synced', count($hashedEmails)));
        $this->adAudienceResource->save($adAudience);
    }
}
