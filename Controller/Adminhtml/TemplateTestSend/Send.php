<?php
declare(strict_types=1);

namespace Ordo\Automation\Controller\Adminhtml\TemplateTestSend;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Mail\Template\TransportBuilder;
use Ordo\Automation\Model\Campaign\Action\SendRetrier;
use Ordo\Automation\Model\ResourceModel\WhatsAppTemplate as WhatsAppTemplateResource;
use Ordo\Automation\Model\Sms\SmsSenderInterface;
use Ordo\Automation\Model\WhatsApp\WhatsAppSender;
use Ordo\Automation\Model\WhatsAppTemplateFactory;

/**
 * "Test send" for Email/SMS/WhatsApp templates, closing the communication-channels ROADMAP.md
 * gap where there was no template preview or test-send anywhere in admin, for any channel.
 * Deliberately does NOT reuse Model\Campaign\Action\Send{Email,Sms,WhatsApp}::execute() as-is:
 * those gate on a real customer_id's consent/quiet-hours/frequency-cap state, which has no
 * meaning for "send one test message to whatever address/number I just typed in" - an explicit,
 * admin-initiated test send should always attempt to send, not silently no-op because the typed
 * address happens to belong to (or not belong to) some customer with withdrawn consent. Instead
 * this calls the same underlying provider abstractions those actions use
 * (TransportBuilder/SmsSenderInterface/WhatsAppSender) directly, through the same SendRetrier for
 * the actual network call.
 *
 * Push is intentionally not supported here - Model\Push\PushSubscriptionManager only knows about
 * real, already-registered browser subscriptions; there is no "address" a push notification could
 * test-send to, so a genuinely equivalent test-send isn't possible for that channel.
 *
 * Every failure path returns a real caught exception's message (not a generic "failed") - a
 * template author test-sending specifically to catch their own typo'd {{var}}/{{1}} placeholder
 * needs to see what actually broke, not just that something did.
 */
class Send extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Ordo_Automation::campaigns';

    private const string E164_PATTERN = '/^\+[1-9]\d{7,14}$/';

    public function __construct(
        Context $context,
        private readonly JsonFactory $resultJsonFactory,
        private readonly TransportBuilder $transportBuilder,
        private readonly SmsSenderInterface $smsSender,
        private readonly WhatsAppSender $whatsAppSender,
        private readonly WhatsAppTemplateFactory $whatsAppTemplateFactory,
        private readonly WhatsAppTemplateResource $whatsAppTemplateResource,
        private readonly SendRetrier $sendRetrier
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $result = $this->resultJsonFactory->create();
        $channel = (string) $this->getRequest()->getParam('channel');

        try {
            $message = match ($channel) {
                'email' => $this->sendTestEmail(),
                'sms' => $this->sendTestSms(),
                'whatsapp' => $this->sendTestWhatsApp(),
                default => throw new \InvalidArgumentException(sprintf('Unknown test-send channel "%s".', $channel)),
            };

            return $result->setData(['success' => true, 'message' => $message]);
        } catch (\Throwable $e) {
            return $result->setData(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    private function sendTestEmail(): string
    {
        $to = trim((string) $this->getRequest()->getParam('to'));
        $subjectMessage = (string) $this->getRequest()->getParam('message', '');

        if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('Enter a valid email address to send the test to.');
        }

        $this->sendRetrier->attempt(function () use ($to, $subjectMessage): void {
            $transport = $this->transportBuilder
                ->setTemplateIdentifier('ordo_campaign_generic')
                ->setTemplateOptions(['area' => \Magento\Framework\App\Area::AREA_FRONTEND, 'store' => 0])
                ->setTemplateVars(['customer_name' => 'Test', 'message' => $subjectMessage])
                ->setFromByScope('general')
                ->addTo($to)
                ->getTransport();

            $transport->sendMessage();
        });

        return sprintf('Test email sent to %s.', $to);
    }

    private function sendTestSms(): string
    {
        $to = trim((string) $this->getRequest()->getParam('to'));
        $message = trim((string) $this->getRequest()->getParam('message', ''));

        if (!preg_match(self::E164_PATTERN, $to)) {
            throw new \InvalidArgumentException('Enter a valid E.164 phone number (e.g. +15551234567) to test with.');
        }

        if ($message === '') {
            throw new \InvalidArgumentException('Enter a message to send.');
        }

        $this->sendRetrier->attempt(fn () => $this->smsSender->send($to, $message));

        return sprintf('Test SMS sent to %s.', $to);
    }

    private function sendTestWhatsApp(): string
    {
        $to = trim((string) $this->getRequest()->getParam('to'));
        $templateId = (int) $this->getRequest()->getParam('template_id');
        $paramsCsv = trim((string) $this->getRequest()->getParam('params', ''));

        if (!preg_match(self::E164_PATTERN, $to)) {
            throw new \InvalidArgumentException('Enter a valid E.164 phone number (e.g. +15551234567) to test with.');
        }

        if ($templateId <= 0) {
            throw new \InvalidArgumentException('Pick a WhatsApp template to test with.');
        }

        $template = $this->whatsAppTemplateFactory->create();
        $this->whatsAppTemplateResource->load($template, $templateId);

        if (!$template->getEntityId() || !$template->isApproved()) {
            throw new \InvalidArgumentException('That template is not an approved WhatsApp template.');
        }

        $params = $paramsCsv === '' ? [] : array_map('trim', explode(',', $paramsCsv));

        $this->sendRetrier->attempt(fn () => $this->whatsAppSender->send(
            $to,
            $template->getMetaTemplateName(),
            $template->getLanguage(),
            $params
        ));

        return sprintf('Test WhatsApp message sent to %s.', $to);
    }
}
