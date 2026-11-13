<?php
declare(strict_types=1);

namespace Ordo\Automation\Plugin\Email;

use Magento\Framework\Mail\EmailMessage;
use Magento\Framework\Mail\EmailMessageInterface;
use Magento\Framework\Mail\EmailMessageInterfaceFactory;
use Ordo\Automation\Model\Email\PendingUnsubscribeHeaderHolder;

/**
 * Same interception seam as Plugin\Email\EmailMessageMessageIdPlugin - see that class's own
 * docblock for why a plugin on the factory itself is the only reachable way to touch the message
 * TransportBuilder::getTransport() builds internally.
 *
 * Sets the RFC 8058 one-click-unsubscribe headers (List-Unsubscribe + List-Unsubscribe-Post) -
 * Gmail/Yahoo's 2024 bulk-sender rules require both on any marketing send. List-Unsubscribe-Post
 * is the fixed value every mail client that supports one-click POST sends against the URL in
 * List-Unsubscribe - see docs/adr/0058-email-one-click-unsubscribe.md.
 *
 * Sets nothing when PendingUnsubscribeHeaderHolder has no url queued (every campaign action
 * other than send_email, and any other TransportBuilder-based email in this Magento install) -
 * this plugin is a no-op for those, same as the Message-ID plugin.
 */
class EmailMessageUnsubscribeHeaderPlugin
{
    private const string LIST_UNSUBSCRIBE_POST_VALUE = 'List-Unsubscribe=One-Click';

    public function __construct(
        private readonly PendingUnsubscribeHeaderHolder $pendingUnsubscribeHeaderHolder
    ) {
    }

    public function afterCreate(
        EmailMessageInterfaceFactory $subject,
        EmailMessageInterface $result
    ): EmailMessageInterface {
        $unsubscribeUrl = $this->pendingUnsubscribeHeaderHolder->consume();
        if ($unsubscribeUrl === null || !$result instanceof EmailMessage) {
            return $result;
        }

        $headers = $result->getSymfonyMessage()->getHeaders();
        $headers->addTextHeader('List-Unsubscribe', '<' . $unsubscribeUrl . '>');
        $headers->addTextHeader('List-Unsubscribe-Post', self::LIST_UNSUBSCRIBE_POST_VALUE);

        return $result;
    }
}
