<?php
declare(strict_types=1);

namespace Ordo\Automation\Cron;

use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\Cron\ReminderEmailSender;
use Ordo\Automation\Model\CustomerMapBuilder;
use Ordo\Automation\Model\CustomerTagManager;
use Ordo\Automation\Setup\Patch\Data\AddSalesRepAttributes;
use Psr\Log\LoggerInterface;

/**
 * One digest email per rep instead of one alert per signal — a rep with 40 accounts should not
 * get 40 separate emails the day a batch of them goes inactive. Groups every customer currently
 * tagged "inactive" by their assigned rep's email and sends each rep a single weekly list.
 */
class SendSalesRepDigest
{
    private const string XML_PATH_EMAIL_TEMPLATE = 'ordo_sales_rep_digest';

    public function __construct(
        private readonly Config $config,
        private readonly CustomerTagManager $customerTagManager,
        private readonly CustomerMapBuilder $customerMapBuilder,
        private readonly ReminderEmailSender $emailSender,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(): void
    {
        if (!$this->config->isSalesRepDigestEnabled()) {
            return;
        }

        $customersByRepEmail = $this->groupInactiveCustomersByRep();

        $sent = 0;
        foreach ($customersByRepEmail as $repEmail => $customerNames) {
            try {
                $this->sendDigest($repEmail, $customerNames);
                $sent++;
            } catch (\Throwable $e) {
                $this->logger->error(sprintf(
                    'Ordo_Automation: failed to send sales rep digest to %s: %s',
                    $repEmail,
                    $e->getMessage()
                ));
            }
        }

        $this->logger->info(sprintf('Ordo_Automation: sent %d sales rep digests.', $sent));
    }

    /**
     * @return array<string, string[]> rep email => list of "Customer Name (customer_id)"
     */
    private function groupInactiveCustomersByRep(): array
    {
        $grouped = [];

        $customerIds = $this->customerTagManager->getCustomerIdsWithTag(TagInactiveCustomers::TAG_INACTIVE);
        $customerMap = $this->customerMapBuilder->build($customerIds);

        foreach ($customerIds as $customerId) {
            if (!isset($customerMap[$customerId])) {
                continue;
            }

            $customer = $customerMap[$customerId];

            $repEmailAttribute = $customer->getCustomAttribute(AddSalesRepAttributes::ATTRIBUTE_REP_EMAIL);
            $repEmailValue = $repEmailAttribute ? $repEmailAttribute->getValue() : null;
            $repEmail = is_scalar($repEmailValue) ? (string) $repEmailValue : '';

            if ($repEmail === '') {
                continue;
            }

            $grouped[$repEmail][] = trim($customer->getFirstname() . ' ' . $customer->getLastname())
                . " (#{$customerId})";
        }

        return $grouped;
    }

    /**
     * @param string[] $customerNames
     */
    private function sendDigest(string $repEmail, array $customerNames): void
    {
        $this->emailSender->send(
            self::XML_PATH_EMAIL_TEMPLATE,
            [
                'customer_count' => count($customerNames),
                'customer_names' => $customerNames,
            ],
            $repEmail
        );
    }
}
