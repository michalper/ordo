<?php
declare(strict_types=1);

namespace Ordo\Automation\Cron;

use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Framework\App\Area;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Mail\Template\TransportBuilder;
use Magento\Framework\Translate\Inline\StateInterface;
use Magento\Store\Model\StoreManagerInterface;
use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\CreditLimitCalculator;
use Ordo\Automation\Model\SalesRepEmailContext;
use Psr\Log\LoggerInterface;

/**
 * Most systems only react once a customer is already blocked at 100% of their credit limit.
 * This warns proactively at a configurable threshold (default 80%) and again if they cross 100%,
 * each band alerted at most once per cooldown period so a customer sitting at 95% doesn't get
 * emailed every single day.
 */
class SendCreditLimitAlerts
{
    private const XML_PATH_EMAIL_TEMPLATE = 'ordo_credit_limit_warning';
    private const XML_PATH_EMAIL_SENDER = 'general';
    private const OVER_LIMIT_BAND = 100;

    public function __construct(
        private readonly Config $config,
        private readonly CreditLimitCalculator $creditLimitCalculator,
        private readonly ResourceConnection $resourceConnection,
        private readonly CustomerRepositoryInterface $customerRepository,
        private readonly TransportBuilder $transportBuilder,
        private readonly StoreManagerInterface $storeManager,
        private readonly StateInterface $inlineTranslation,
        private readonly SalesRepEmailContext $salesRepEmailContext,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(): void
    {
        if (!$this->config->isCreditLimitAlertEnabled()) {
            return;
        }

        $warningThreshold = $this->config->getCreditLimitWarningThreshold();
        $sent = 0;

        foreach ($this->creditLimitCalculator->getCustomerIdsWithCreditLimit() as $customerId) {
            $utilization = $this->creditLimitCalculator->getUtilizationPercent($customerId);
            $band = $this->resolveBand($utilization, $warningThreshold);

            if ($band === null) {
                continue;
            }

            if ($this->alertedRecently($customerId, $band)) {
                continue;
            }

            try {
                $this->sendAlert($customerId, $utilization, $band);
                $this->logAlert($customerId, $band, $utilization);
                $sent++;
            } catch (\Throwable $e) {
                $this->logger->error(
                    sprintf('Ordo_Automation: failed to send credit limit alert for customer #%d: %s', $customerId, $e->getMessage())
                );
            }
        }

        $this->logger->info(sprintf('Ordo_Automation: sent %d credit limit alerts.', $sent));
    }

    private function resolveBand(float $utilization, int $warningThreshold): ?int
    {
        if ($utilization >= self::OVER_LIMIT_BAND) {
            return self::OVER_LIMIT_BAND;
        }

        if ($utilization >= $warningThreshold) {
            return $warningThreshold;
        }

        return null;
    }

    private function alertedRecently(int $customerId, int $band): bool
    {
        $cooldownDays = $this->config->getCreditLimitAlertCooldownDays();
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName('ordo_credit_limit_alert_log');
        $cutoff = date('Y-m-d H:i:s', (int) strtotime("-{$cooldownDays} days"));

        $count = $connection->fetchOne(
            $connection->select()
                ->from($table, 'COUNT(*)')
                ->where('customer_id = ?', $customerId)
                ->where('threshold_percent = ?', $band)
                ->where('sent_at >= ?', $cutoff)
        );

        return (int) $count > 0;
    }

    private function sendAlert(int $customerId, float $utilization, int $band): void
    {
        $customer = $this->customerRepository->getById($customerId);
        $store = $this->storeManager->getStore();

        $this->inlineTranslation->suspend();

        $transport = $this->transportBuilder
            ->setTemplateIdentifier(self::XML_PATH_EMAIL_TEMPLATE)
            ->setTemplateOptions(['area' => Area::AREA_FRONTEND, 'store' => $store->getId()])
            ->setTemplateVars(array_merge([
                'customer_name' => $customer->getFirstname(),
                'utilization_percent' => $utilization,
                'credit_limit' => $this->creditLimitCalculator->getCreditLimit($customerId),
                'used_credit' => $this->creditLimitCalculator->getUsedCredit($customerId),
                'is_over_limit' => $band >= self::OVER_LIMIT_BAND,
                'is_within_limit' => $band < self::OVER_LIMIT_BAND,
                'store' => $store,
            ], $this->salesRepEmailContext->getForCustomer($customerId)))
            ->setFromByScope(self::XML_PATH_EMAIL_SENDER, $store->getId())
            ->addTo($customer->getEmail(), $customer->getFirstname())
            ->getTransport();

        $transport->sendMessage();

        $this->inlineTranslation->resume();
    }

    private function logAlert(int $customerId, int $band, float $utilization): void
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName('ordo_credit_limit_alert_log');

        $connection->insert($table, [
            'customer_id' => $customerId,
            'threshold_percent' => $band,
            'utilization_percent' => $utilization,
            'sent_at' => date('Y-m-d H:i:s'),
        ]);
    }
}
