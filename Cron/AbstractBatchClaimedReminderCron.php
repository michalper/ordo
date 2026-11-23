<?php
declare(strict_types=1);

namespace Ordo\Automation\Cron;

use Magento\Customer\Api\Data\CustomerInterface;
use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\ConsentChannel;
use Ordo\Automation\Model\ConsentManager;
use Ordo\Automation\Model\ConsentTopic;
use Ordo\Automation\Model\Cron\CronRunLogger;
use Ordo\Automation\Model\Cron\ReminderEmailSender;
use Ordo\Automation\Model\Cron\ReminderLogStore;
use Ordo\Automation\Model\CustomerMapBuilder;
use Ordo\Automation\Model\CustomerStoreResolver;
use Ordo\Automation\Model\SalesRepEmailContext;
use Ordo\Automation\Model\TriggerOutcomeLogger;

/**
 * Shared by SendReorderReminders and SendOfferExpiryReminders - the two reminder crons whose
 * shape is genuinely identical (same constructor dependencies in the same order, same
 * fetch-rows/batch-consent/batch-pre-filter/claim-then-send/rollback-on-failure shell), only the
 * query, claim-row shape, and template vars differing.
 *
 * Deliberately NOT shared with SendCreditLimitAlerts (no row/entity collection at all - it scans
 * customer_id directly and computes a utilization "band" per customer inside the loop) or
 * SendStalledPaymentReminders (no TriggerOutcomeLogger/SalesRepEmailContext, plain array rows
 * from a hand-written LEFT JOIN pre-filter instead of ReminderLogStore::fetchExistingIds()) -
 * forcing either of those into this same shape would mean generalizing the shell around their
 * real structural differences rather than their incidental ones.
 */
abstract class AbstractBatchClaimedReminderCron
{
    public function __construct(
        protected readonly Config $config,
        private readonly CustomerMapBuilder $customerMapBuilder,
        private readonly ReminderEmailSender $emailSender,
        private readonly ReminderLogStore $reminderLogStore,
        protected readonly SalesRepEmailContext $salesRepEmailContext,
        private readonly ConsentManager $consentManager,
        private readonly TriggerOutcomeLogger $triggerOutcomeLogger,
        private readonly CronRunLogger $cronRunLogger,
        private readonly CustomerStoreResolver $customerStoreResolver
    ) {
    }

    abstract protected function isEnabled(): bool;

    abstract protected function reminderLogTable(): string;

    abstract protected function emailTemplatePath(): string;

    abstract protected function consentTopic(): ConsentTopic;

    abstract protected function triggerOutcomeCode(): string;

    /**
     * Singular, e.g. "reorder reminder" - used both for the failure message and (with a plain
     * "s" appended) the run summary.
     */
    abstract protected function reminderLabel(): string;

    /**
     * Singular noun for the row this reminder is about, e.g. "cycle" - only used in the failure
     * message ("send reorder reminder for cycle #5").
     */
    abstract protected function rowNoun(): string;

    abstract protected function logIdColumn(): string;

    /**
     * @return array<string, int|string>
     */
    abstract protected function logStaticConditions(): array;

    /**
     * @return array<int, array{id: int, customerId: int, row: object}>
     */
    abstract protected function fetchDueRows(): array;

    /**
     * @return array<string, int|string>
     */
    abstract protected function reminderLogMatchConditions(int $rowId): array;

    /**
     * @return array<string, mixed>
     */
    abstract protected function buildReminderLogRow(int $rowId): array;

    /**
     * @return array<string, mixed>
     */
    abstract protected function buildTemplateVars(object $row, CustomerInterface $customer): array;

    public function execute(): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        $dueRows = $this->fetchDueRows();

        $rowIds = array_column($dueRows, 'id');
        $customerIds = array_column($dueRows, 'customerId');

        $customerMap = $this->customerMapBuilder->build($customerIds);
        // One query for the whole batch instead of one hasConsent() call per row inside the loop
        // below - found via a performance audit, same reasoning as
        // CreditLimitCalculator::getUsedCreditForCustomers().
        $consentByCustomer = $this->consentManager->hasTopicConsentForCustomers(
            $customerIds,
            ConsentChannel::Email,
            $this->consentTopic()
        );
        // Same batching as the consent check above, for the "already sent" pre-filter - found via
        // a performance audit.
        $alreadySent = $this->reminderLogStore->fetchExistingIds(
            $this->reminderLogTable(),
            $this->logIdColumn(),
            $rowIds,
            $this->logStaticConditions()
        );

        $sent = 0;
        foreach ($dueRows as $due) {
            if (isset($alreadySent[$due['id']])) {
                continue;
            }

            $customerId = $due['customerId'];
            if (!isset($customerMap[$customerId])) {
                continue;
            }

            // A customer who opted out of email must never receive this reminder, same consent
            // gate every other channel's send action applies before sending anything.
            if (!($consentByCustomer[$customerId] ?? true)) {
                continue;
            }

            $customer = $customerMap[$customerId];
            $row = $due['row'];

            try {
                // Claim (log) BEFORE sending, not after - see ReminderLogStore::deleteMatching()'s
                // own docblock for why: a crash between a successful send and the log write must
                // never cause a resend next tick, and a genuine send failure rolls the claim back
                // so this row is retried. claim() (not a plain insert()) re-checks "already sent"
                // atomically - the batched $alreadySent check above is only a fast pre-filter, not
                // the actual guard against a double-send.
                $claimed = $this->reminderLogStore->claimAndRun(
                    $this->reminderLogTable(),
                    $this->reminderLogMatchConditions($due['id']),
                    $this->buildReminderLogRow($due['id']),
                    function () use ($row, $customer, $customerId): void {
                        $this->emailSender->send(
                            $this->emailTemplatePath(),
                            $this->buildTemplateVars($row, $customer),
                            $customer->getEmail(),
                            $customer->getFirstname(),
                            $this->emailSender->getStore($this->customerStoreResolver->resolveStoreId($customer))
                        );
                        $this->triggerOutcomeLogger->logSent($this->triggerOutcomeCode(), $customerId);
                    }
                );
            } catch (\Throwable $e) {
                $this->cronRunLogger->logFailure(
                    sprintf('send %s for %s #%d', $this->reminderLabel(), $this->rowNoun(), $due['id']),
                    $e
                );
                continue;
            }

            if ($claimed) {
                $sent++;
            }
        }

        $this->cronRunLogger->logSummary(sprintf('sent %d %ss', $sent, $this->reminderLabel()));
    }
}
