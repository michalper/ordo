<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\Cron;

use Magento\Framework\App\Area;
use Magento\Framework\Mail\Template\TransportBuilder;
use Magento\Framework\Translate\Inline\StateInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * "Suspend inline translation, build a transport for a template with the given vars, send it,
 * resume" — extracted after SonarCloud flagged this exact shape duplicated (only the template
 * identifier/vars/recipient differed) across five reminder/alert/digest cron classes
 * (SendWinBackEmails, SendOfferExpiryReminders, SendReorderReminders, SendCreditLimitAlerts,
 * SendSalesRepDigest). Every caller sent from the same 'general' sender scope, so that's kept
 * as a fixed default here rather than a parameter every caller would have passed identically.
 */
class ReminderEmailSender
{
    private const string XML_PATH_EMAIL_SENDER = 'general';

    public function __construct(
        private readonly TransportBuilder $transportBuilder,
        private readonly StoreManagerInterface $storeManager,
        private readonly StateInterface $inlineTranslation
    ) {
    }

    /**
     * The current store — exposed so a caller can fold store-dependent values (e.g. via
     * SalesRepEmailContext::getForCustomer()) into $templateVars before calling send().
     */
    public function getStore(): StoreInterface
    {
        return $this->storeManager->getStore();
    }

    /**
     * @param array<string, mixed> $templateVars Merged with 'store' => getStore() — don't set
     *   'store' yourself.
     */
    public function send(string $templateIdentifier, array $templateVars, string $toAddress, string $toName = ''): void
    {
        $store = $this->getStore();

        $this->inlineTranslation->suspend();

        $transport = $this->transportBuilder
            ->setTemplateIdentifier($templateIdentifier)
            ->setTemplateOptions(['area' => Area::AREA_FRONTEND, 'store' => $store->getId()])
            ->setTemplateVars($templateVars + ['store' => $store])
            ->setFromByScope(self::XML_PATH_EMAIL_SENDER, $store->getId())
            ->addTo($toAddress, $toName)
            ->getTransport();

        $transport->sendMessage();

        $this->inlineTranslation->resume();
    }
}
