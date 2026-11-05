<?php
declare(strict_types=1);

namespace Ordo\Automation\Controller\Adminhtml\EmailTemplateDraft;

use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Phrase;
use Ordo\Automation\Controller\Adminhtml\Shared\DeletesEntityTrait;
use Ordo\Automation\Model\EmailTemplateDraftFactory;
use Ordo\Automation\Model\ResourceModel\EmailTemplateDraft as EmailTemplateDraftResource;

/**
 * Discards a draft without publishing it - the live template is never touched by this action.
 */
class Delete extends AbstractEmailTemplateDraftAction implements HttpPostActionInterface
{
    use DeletesEntityTrait;

    public function __construct(
        Context $context,
        private readonly EmailTemplateDraftFactory $draftFactory,
        private readonly EmailTemplateDraftResource $draftResource
    ) {
        parent::__construct($context);
    }

    protected function deleteEntity(int $entityId): void
    {
        $draft = $this->draftFactory->create();
        $this->draftResource->load($draft, $entityId);
        $this->draftResource->delete($draft);
    }

    protected function getMissingIdMessage(): Phrase
    {
        return __('Missing email template draft id.');
    }

    protected function getDeletedMessage(): Phrase
    {
        return __('The email template draft has been deleted.');
    }

    protected function getDeleteErrorMessage(\Throwable $e): Phrase
    {
        return __('Could not delete the email template draft: %1', $e->getMessage());
    }
}
