<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\Campaign\Action;

use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Framework\App\Area;
use Magento\Framework\Mail\Template\TransportBuilder;
use Magento\Framework\Translate\Inline\StateInterface;
use Magento\Store\Model\StoreManagerInterface;
use Ordo\Automation\Api\Campaign\ActionInterface;
use Psr\Log\LoggerInterface;

/**
 * Params: {"template": "ordo_campaign_generic", "message": "optional static text"}.
 * Context must include "customer_id" (email resolved via CustomerRepositoryInterface).
 * Every scalar value in $context becomes a template variable, so "send_email" after a
 * "generate_coupon" action on the same campaign can render {{var coupon_code}} for free.
 */
class SendEmail implements ActionInterface
{
    private const string XML_PATH_EMAIL_SENDER = 'general';

    public function __construct(
        private readonly CustomerRepositoryInterface $customerRepository,
        private readonly TransportBuilder $transportBuilder,
        private readonly StoreManagerInterface $storeManager,
        private readonly StateInterface $inlineTranslation,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(array &$context, array $params): void
    {
        $customerId = (int) ($context['customer_id'] ?? 0);
        $templateIdentifier = (string) ($params['template'] ?? '');

        if ($customerId <= 0 || $templateIdentifier === '') {
            $this->logger->error(
                'Ordo_Automation: send_email action is missing customer_id in context or "template" in params.'
            );
            return;
        }

        try {
            $customer = $this->customerRepository->getById($customerId);
        } catch (\Throwable $e) {
            return;
        }

        $store = $this->storeManager->getStore();
        $templateVars = array_merge(
            array_filter($context, is_scalar(...)),
            [
                'customer_name' => $customer->getFirstname(),
                'message' => (string) ($params['message'] ?? ''),
                'store' => $store,
            ]
        );

        $this->inlineTranslation->suspend();

        try {
            $transport = $this->transportBuilder
                ->setTemplateIdentifier($templateIdentifier)
                ->setTemplateOptions(['area' => Area::AREA_FRONTEND, 'store' => $store->getId()])
                ->setTemplateVars($templateVars)
                ->setFromByScope(self::XML_PATH_EMAIL_SENDER, $store->getId())
                ->addTo($customer->getEmail(), $customer->getFirstname())
                ->getTransport();

            $transport->sendMessage();
        } catch (\Throwable $e) {
            $this->logger->error(sprintf(
                'Ordo_Automation: campaign send_email action failed for customer #%d: %s',
                $customerId,
                $e->getMessage()
            ));
        } finally {
            $this->inlineTranslation->resume();
        }
    }
}
