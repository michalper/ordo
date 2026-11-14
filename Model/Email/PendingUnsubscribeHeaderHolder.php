<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\Email;

/**
 * Same request-scoped handoff shape as PendingMessageIdHolder (see that class's own docblock for
 * why a small shared holder is the only way to reach the message
 * Plugin\Email\EmailMessageUnsubscribeHeaderPlugin intercepts) - carries the unsubscribe URL
 * Model\Campaign\Action\SendEmail just built for the customer being emailed across to the plugin
 * that actually sets the List-Unsubscribe/List-Unsubscribe-Post headers on the outgoing message.
 */
class PendingUnsubscribeHeaderHolder
{
    private ?string $unsubscribeUrl = null;

    public function set(string $unsubscribeUrl): void
    {
        $this->unsubscribeUrl = $unsubscribeUrl;
    }

    /**
     * Read-and-clear - every SendEmail::execute() call sets a fresh url right before its own
     * getTransport() call and this consumes it immediately after, so a batch cron sending many
     * emails in one request never leaks one email's url onto the next.
     */
    public function consume(): ?string
    {
        $unsubscribeUrl = $this->unsubscribeUrl;
        $this->unsubscribeUrl = null;

        return $unsubscribeUrl;
    }
}
