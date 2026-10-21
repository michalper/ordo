<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\Cron;

use Magento\Framework\App\Area;
use Magento\Framework\Mail\Template\TransportBuilder;
use Magento\Framework\Translate\Inline\StateInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use Ordo\Automation\Model\Email\PendingMessageIdHolder;

/**
 * "Suspend inline translation, build a transport for a template with the given vars, send it,
 * resume" — extracted after SonarCloud flagged this exact shape duplicated (only the template
 * identifier/vars/recipient differed) across five reminder/alert/digest cron classes
 * (SendWinBackEmails, SendOfferExpiryReminders, SendReorderReminders, SendCreditLimitAlerts,
 * SendSalesRepDigest). Every caller sent from the same 'general' sender scope, so that's kept
 * as a fixed default here rather than a parameter every caller would have passed identically.
 * Order-scoped emails (the approval request/escalation) pass the order's own store instead of
 * relying on the "current" one — see Observer\HoldOrderForApproval's multi-store comment.
 */
class ReminderEmailSender
{
    private const string XML_PATH_EMAIL_SENDER = 'general';

    public function __construct(
        private readonly TransportBuilder $transportBuilder,
        private readonly StoreManagerInterface $storeManager,
        private readonly StateInterface $inlineTranslation,
        private readonly PendingMessageIdHolder $pendingMessageIdHolder
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
     * @param array<string, mixed> $templateVars Merged with 'store' => the effective store —
     *   don't set 'store' yourself.
     * @param StoreInterface|null $store Overrides the current store for template scope, sender
     *   scope, and the 'store' template var alike.
     * @param string|null $messageId When set, the outgoing message carries this Message-ID (via
     *   PendingMessageIdHolder / Plugin\Email\EmailMessageMessageIdPlugin) so a later
     *   delivery-status webhook event can be correlated back to this exact send — see
     *   Cron\SendAbandonedCartReminders, the one reminder that needs it.
     */
    public function send(
        string $templateIdentifier,
        array $templateVars,
        string $toAddress,
        string $toName = '',
        ?StoreInterface $store = null,
        ?string $messageId = null
    ): void {
        $store ??= $this->getStore();

        $this->inlineTranslation->suspend();
        if ($messageId !== null) {
            $this->pendingMessageIdHolder->set($messageId);
        }

        try {
            $transport = $this->transportBuilder
                ->setTemplateIdentifier($templateIdentifier)
                ->setTemplateOptions(['area' => Area::AREA_FRONTEND, 'store' => $store->getId()])
                ->setTemplateVars($templateVars + ['store' => $store])
                ->setFromByScope(self::XML_PATH_EMAIL_SENDER, $store->getId())
                ->addTo($toAddress, $toName)
                ->getTransport();

            $transport->sendMessage();
        } finally {
            if ($messageId !== null) {
                $this->pendingMessageIdHolder->consume();
            }
            $this->inlineTranslation->resume();
        }
    }
}
