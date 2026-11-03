<?php
declare(strict_types=1);

namespace Ordo\Automation\Cron;

use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\ConsentChannel;
use Ordo\Automation\Model\ConsentManager;
use Ordo\Automation\Model\Cron\CronRunLogger;
use Ordo\Automation\Model\Cron\ReminderEmailSender;
use Ordo\Automation\Model\CustomerMapBuilder;
use Ordo\Automation\Model\CustomerStoreResolver;
use Ordo\Automation\Model\CustomerTagManager;
use Ordo\Automation\Model\TriggerOutcomeLogger;

/**
 * Emails everyone TagInactiveCustomers has tagged "inactive" who hasn't already received a
 * win-back email (tracked as its own tag, so it survives independently of the inactive/active
 * flip-flopping and never sends twice).
 */
class SendWinBackEmails
{
    private const string XML_PATH_EMAIL_TEMPLATE = 'ordo_win_back_email';
    public const TAG_WIN_BACK_SENT = 'win_back_sent';

    public function __construct(
        private readonly Config $config,
        private readonly CustomerTagManager $customerTagManager,
        private readonly CustomerMapBuilder $customerMapBuilder,
        private readonly ReminderEmailSender $emailSender,
        private readonly ConsentManager $consentManager,
        private readonly TriggerOutcomeLogger $triggerOutcomeLogger,
        private readonly CronRunLogger $cronRunLogger,
        private readonly CustomerStoreResolver $customerStoreResolver
    ) {
    }

    public function execute(): void
    {
        if (!$this->config->isLifecycleEmailsEnabled()) {
            return;
        }

        $customerIds = $this->customerTagManager->getCustomerIdsWithTag(TagInactiveCustomers::TAG_INACTIVE);
        $customerMap = $this->customerMapBuilder->build($customerIds);
        // One query each for the whole batch instead of one hasTag()/hasConsent() call per
        // candidate below - found via a performance audit, same reasoning as
        // ConsentManager::hasConsentForCustomers().
        $alreadySent = array_flip(
            $this->customerTagManager->getCustomerIdsWithTagFromSet($customerIds, self::TAG_WIN_BACK_SENT)
        );
        $consentByCustomer = $this->consentManager->hasConsentForCustomers($customerIds, ConsentChannel::Email);

        $sent = 0;
        foreach ($customerIds as $customerId) {
            if (isset($alreadySent[$customerId])) {
                continue;
            }

            if (!isset($customerMap[$customerId])) {
                continue;
            }

            // A customer who opted out of email must never receive this marketing email, same
            // consent gate every other channel's send action applies before sending anything.
            if (!($consentByCustomer[$customerId] ?? true)) {
                continue;
            }

            // Claim (tag) BEFORE sending, not after - a crash between a successful send and the
            // tag write must never cause a resend on the next tick. If the send itself then
            // fails, the tag is removed so this customer is retried next run.
            $this->customerTagManager->addTag($customerId, self::TAG_WIN_BACK_SENT);

            try {
                $customer = $customerMap[$customerId];
                $this->emailSender->send(
                    self::XML_PATH_EMAIL_TEMPLATE,
                    ['customer_name' => $customer->getFirstname()],
                    $customer->getEmail(),
                    $customer->getFirstname(),
                    $this->emailSender->getStore($this->customerStoreResolver->resolveStoreId($customer))
                );
                $this->triggerOutcomeLogger->logSent(TriggerOutcomeLogger::TRIGGER_WIN_BACK, $customerId);
                $sent++;
            } catch (\Throwable $e) {
                $this->customerTagManager->removeTag($customerId, self::TAG_WIN_BACK_SENT);
                $this->cronRunLogger->logFailure(
                    sprintf('send win-back email to customer #%d', $customerId),
                    $e
                );
            }
        }

        $this->cronRunLogger->logSummary(sprintf('sent %d win-back emails', $sent));
    }
}
