<?php
declare(strict_types=1);

namespace Ordo\Automation\Cron;

use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\App\Area;
use Magento\Framework\Mail\Template\TransportBuilder;
use Magento\Framework\Translate\Inline\StateInterface;
use Magento\Store\Model\StoreManagerInterface;
use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\CustomerTagManager;
use Psr\Log\LoggerInterface;

/**
 * Emails everyone TagInactiveCustomers has tagged "inactive" who hasn't already received a
 * win-back email (tracked as its own tag, so it survives independently of the inactive/active
 * flip-flopping and never sends twice).
 */
class SendWinBackEmails
{
    private const XML_PATH_EMAIL_TEMPLATE = 'ordo_win_back_email';
    private const XML_PATH_EMAIL_SENDER = 'general';
    public const TAG_WIN_BACK_SENT = 'win_back_sent';

    public function __construct(
        private readonly Config $config,
        private readonly CustomerTagManager $customerTagManager,
        private readonly CustomerRepositoryInterface $customerRepository,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder,
        private readonly TransportBuilder $transportBuilder,
        private readonly StoreManagerInterface $storeManager,
        private readonly StateInterface $inlineTranslation,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(): void
    {
        if (!$this->config->isLifecycleEmailsEnabled()) {
            return;
        }

        $customerIds = $this->customerTagManager->getCustomerIdsWithTag(TagInactiveCustomers::TAG_INACTIVE);
        $customerMap = $this->buildCustomerMap($customerIds);

        $sent = 0;
        foreach ($customerIds as $customerId) {
            if ($this->customerTagManager->hasTag($customerId, self::TAG_WIN_BACK_SENT)) {
                continue;
            }

            if (!isset($customerMap[$customerId])) {
                continue;
            }

            try {
                $this->sendEmail($customerMap[$customerId]);
                $this->customerTagManager->addTag($customerId, self::TAG_WIN_BACK_SENT);
                $sent++;
            } catch (\Throwable $e) {
                $this->logger->error(sprintf(
                    'Ordo_Automation: failed to send win-back email to customer #%d: %s',
                    $customerId,
                    $e->getMessage()
                ));
            }
        }

        $this->logger->info(sprintf('Ordo_Automation: sent %d win-back emails.', $sent));
    }

    /**
     * @param int[] $customerIds
     * @return array<int, CustomerInterface>
     */
    private function buildCustomerMap(array $customerIds): array
    {
        if ($customerIds === []) {
            return [];
        }

        $searchCriteria = $this->searchCriteriaBuilder
            ->addFilter('entity_id', array_values(array_unique($customerIds)), 'in')
            ->create();

        $customerMap = [];
        foreach ($this->customerRepository->getList($searchCriteria)->getItems() as $customer) {
            $customerMap[(int) $customer->getId()] = $customer;
        }

        return $customerMap;
    }

    private function sendEmail(CustomerInterface $customer): void
    {
        $store = $this->storeManager->getStore();

        $this->inlineTranslation->suspend();

        $transport = $this->transportBuilder
            ->setTemplateIdentifier(self::XML_PATH_EMAIL_TEMPLATE)
            ->setTemplateOptions(['area' => Area::AREA_FRONTEND, 'store' => $store->getId()])
            ->setTemplateVars([
                'customer_name' => $customer->getFirstname(),
                'store' => $store,
            ])
            ->setFromByScope(self::XML_PATH_EMAIL_SENDER, $store->getId())
            ->addTo($customer->getEmail(), $customer->getFirstname())
            ->getTransport();

        $transport->sendMessage();

        $this->inlineTranslation->resume();
    }
}
