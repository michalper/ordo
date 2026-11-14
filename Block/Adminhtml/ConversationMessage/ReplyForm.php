<?php
declare(strict_types=1);

namespace Ordo\Automation\Block\Adminhtml\ConversationMessage;

use Magento\Backend\Block\Template;

/**
 * Plain, server-rendered inline reply form on the Conversations grid page (view/adminhtml/layout/
 * ordo_conversationmessage_index.xml) - no custom JS/knockout, same "a POST form plus a
 * getBlockHtml('formkey') child block" shape Block\Adminhtml\Import\Form already uses for the
 * other POST-with-a-text-body form in this module. "Message ID" is typed or pre-filled from the
 * grid's own "Reply" row action (Ui\Component\Listing\Column\ConversationMessageActions) via the
 * "reply_to" query param, rather than the admin having to use a knockout modal per row - see
 * docs/adr/0060 for why this, not a per-row inline box, was the chosen shape for v1.
 */
class ReplyForm extends Template
{
    public function getReplyUrl(): string
    {
        return $this->getUrl('ordo/conversationmessage/reply');
    }

    public function getPrefilledEntityId(): int
    {
        return (int) $this->getRequest()->getParam('reply_to');
    }
}
