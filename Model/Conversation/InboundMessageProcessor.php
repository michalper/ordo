<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\Conversation;

use Ordo\Automation\Model\ConsentChannel;
use Ordo\Automation\Model\ConsentManager;
use Ordo\Automation\Model\ConversationMessageFactory;
use Ordo\Automation\Model\ResourceModel\ConversationMessage as ConversationMessageResource;
use Ordo\Automation\Model\ResourceModel\MessageLog\CollectionFactory as MessageLogCollectionFactory;
use Psr\Log\LoggerInterface;

/**
 * The single funnel every inbound reply passes through, shared by Controller\Sms\Reply and
 * Controller\WhatsApp\Webhook, so the two providers can't drift on the one behavior that
 * actually matters here: an unconditional, non-configurable STOP-keyword consent revocation
 * (see StopKeywordDetector). This is deliberately NOT gated behind any config toggle — the
 * roadmap item that created this class explicitly calls the keyword handling "mandatory", and a
 * disable switch would defeat the whole point of it being unconditional.
 *
 * customer_id resolution is best-effort (see ordo_conversation_message's own db_schema.xml
 * comment) — a reply from a number this store never recorded a customer against is still stored
 * (for the conversation view / audit trail), but a STOP reply from an unresolvable number cannot
 * revoke consent through Model\ConsentManager, which is keyed by customer_id. That gap is logged
 * as an error (not silently dropped) so it surfaces for manual follow-up.
 */
class InboundMessageProcessor
{
    public function __construct(
        private readonly ConversationMessageFactory $conversationMessageFactory,
        private readonly ConversationMessageResource $conversationMessageResource,
        private readonly MessageLogCollectionFactory $messageLogCollectionFactory,
        private readonly ConsentManager $consentManager,
        private readonly StopKeywordDetector $stopKeywordDetector,
        private readonly LoggerInterface $logger
    ) {
    }

    public function process(
        ConsentChannel $channel,
        string $fromAddress,
        string $body,
        ?string $providerMessageId
    ): void {
        $customerId = $this->resolveCustomerId($fromAddress);
        $isStopKeyword = $this->stopKeywordDetector->matches($body);

        $message = $this->conversationMessageFactory->create();
        $message->setChannel($channel->value);
        $message->setCustomerId($customerId);
        $message->setFromAddress($fromAddress);
        $message->setBody($body);
        $message->setProviderMessageId($providerMessageId);
        $message->setIsStopKeyword($isStopKeyword);
        $this->conversationMessageResource->save($message);

        if (!$isStopKeyword) {
            return;
        }

        if ($customerId === null) {
            $this->logger->error(sprintf(
                'Ordo_Automation: received a STOP-keyword %s reply from "%s" but could not resolve it to a '
                . 'customer_id - consent was NOT automatically revoked. Requires manual follow-up.',
                $channel->value,
                $fromAddress
            ));

            return;
        }

        $this->consentManager->setConsent($customerId, $channel, false, 'stop_keyword');

        $this->logger->info(sprintf(
            'Ordo_Automation: revoked %s consent for customer #%d after a STOP-keyword reply from "%s".',
            $channel->value,
            $customerId,
            $fromAddress
        ));
    }

    /**
     * Best-effort match against ordo_message_log.to_address — every outbound send already ties a
     * phone number to the customer_id it was sent to, so this reuses that link rather than a
     * separate phone-book table. Picks the most recently sent match if there's more than one.
     */
    private function resolveCustomerId(string $fromAddress): ?int
    {
        $collection = $this->messageLogCollectionFactory->create();
        $collection->addFieldToFilter('to_address', $fromAddress);
        $collection->addFieldToFilter('customer_id', ['notnull' => true]);
        $collection->setOrder('sent_at', $collection::SORT_ORDER_DESC);
        $collection->setPageSize(1);

        $log = $collection->getFirstItem();

        return $log->getId() ? $log->getCustomerId() : null;
    }
}
