<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\ReorderCycle;

/**
 * The customer has opted out of email (ConsentManager) - thrown by ReorderReminderSender::sendNow()
 * so Controller\Adminhtml\ReorderCycle\SendReminder can show a specific, actionable message
 * instead of a generic "send failed" one.
 */
class OptedOutException extends \RuntimeException
{
}
