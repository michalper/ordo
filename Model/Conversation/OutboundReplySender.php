<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\Conversation;

use Ordo\Automation\Model\ConsentChannel;
use Ordo\Automation\Model\ConsentManager;
use Ordo\Automation\Model\ConversationMessage;
use Ordo\Automation\Model\ConversationMessageFactory;
use Ordo\Automation\Model\ResourceModel\ConversationMessage as ConversationMessageResource;
use Ordo\Automation\Model\Sms\SmsSenderInterface;
use Ordo\Automation\Model\WhatsApp\WhatsAppSender;
use Psr\Log\LoggerInterface;

/**
 * The sibling Model\Conversation\InboundMessageProcessor deliberately does not have: a two-way
 * conversation is otherwise receive-only (see that class's own docblock). This is the one new
 * write path that ever sends FROM the admin back to a customer over SMS/WhatsApp, invoked only by
 * Controller\Adminhtml\ConversationMessage\Reply — never by InboundMessageProcessor itself, and
 * never by the campaign engine (Model\Campaign\Action\SendSms/SendWhatsApp keep their own,
 * separate consent/quiet-hours/frequency-cap gated path). See docs/adr/0060 for why this is a
 * separate class rather than a change to InboundMessageProcessor.
 *
 * Replies always go back to the ORIGINAL inbound message's own from_address — the admin is
 * answering a specific conversation, not sending to an arbitrary number — and re-checks consent
 * through the same Model\ConsentManager every other send in this module checks, even though the
 * admin is replying to a message that just arrived (a STOP reply could have arrived, and been
 * processed by InboundMessageProcessor, in between the customer's message and the admin's reply).
 */
class OutboundReplySender
{
    public function __construct(
        private readonly ConversationMessageFactory $conversationMessageFactory,
        private readonly ConversationMessageResource $conversationMessageResource,
        private readonly ConsentManager $consentManager,
        private readonly SmsSenderInterface $smsSender,
        private readonly WhatsAppSender $whatsAppSender,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @throws \InvalidArgumentException on bad input (empty body, unknown/non-inbound entity_id).
     * @throws \RuntimeException when sending is refused for a business reason (no customer
     *   resolved, consent withdrawn, unrecognized channel) or when the underlying sender itself
     *   throws — the real send failure propagates as-is, this method never swallows it.
     */
    public function reply(int $entityId, string $body): ConversationMessage
    {
        $body = trim($body);
        if ($body === '') {
            throw new \InvalidArgumentException('Enter a reply message.');
        }

        $original = $this->loadInboundMessage($entityId);
        $customerId = $original->getCustomerId();
        if ($customerId === null) {
            // Same conservative stance as InboundMessageProcessor's own unresolvable-customer
            // case: refuse rather than send with no way to verify consent, and log it so it
            // surfaces for manual follow-up instead of silently failing.
            $this->logger->error(sprintf(
                'Ordo_Automation: refused to send a reply to conversation message #%d - no customer_id'
                . ' could be resolved for it, so consent cannot be verified.',
                $entityId
            ));

            throw new \RuntimeException(
                'Cannot reply: no customer could be resolved for this conversation, so consent cannot be verified.'
            );
        }

        $channel = ConsentChannel::tryFrom($original->getChannel());
        if (!$channel instanceof ConsentChannel) {
            throw new \RuntimeException(sprintf(
                'Cannot reply: conversation message #%d has an unrecognized channel "%s".',
                $entityId,
                $original->getChannel()
            ));
        }

        if (!$this->consentManager->hasConsent($customerId, $channel)) {
            throw new \RuntimeException(sprintf(
                'Cannot reply: customer #%d has opted out of %s.',
                $customerId,
                $channel->value
            ));
        }

        $toPhone = $original->getFromAddress();
        $providerMessageId = match ($channel) {
            ConsentChannel::Sms => $this->smsSender->send($toPhone, $body),
            ConsentChannel::WhatsApp => $this->whatsAppSender->sendFreeform($toPhone, $body),
            default => throw new \RuntimeException(sprintf('Unsupported reply channel "%s".', $channel->value)),
        };

        $reply = $this->conversationMessageFactory->create();
        $reply->setChannel($original->getChannel());
        $reply->setCustomerId($customerId);
        $reply->setFromAddress($toPhone);
        $reply->setBody($body);
        $reply->setProviderMessageId($providerMessageId);
        $reply->setIsStopKeyword(false);
        $reply->setDirection(ConversationMessage::DIRECTION_OUTBOUND);
        $this->conversationMessageResource->save($reply);

        $this->logger->info(sprintf(
            'Ordo_Automation: admin reply sent to customer #%d over %s (in reply to conversation message #%d).',
            $customerId,
            $channel->value,
            $entityId
        ));

        return $reply;
    }

    private function loadInboundMessage(int $entityId): ConversationMessage
    {
        $original = $this->conversationMessageFactory->create();
        $this->conversationMessageResource->load($original, $entityId);

        if (!$original->getId()) {
            throw new \InvalidArgumentException(sprintf('Conversation message #%d was not found.', $entityId));
        }

        if ($original->getDirection() !== ConversationMessage::DIRECTION_INBOUND) {
            throw new \InvalidArgumentException('Only an inbound conversation message can be replied to.');
        }

        return $original;
    }
}
