<?php
declare(strict_types=1);

namespace Ordo\Automation\Controller\Adminhtml\Shared;

use Magento\Framework\Phrase;

/**
 * Shared execute() body for every entity's single-record admin Delete controller — was seven
 * byte-identical copies (Segment, ScoreRule, LeadRoutingRule, FreeGiftOffer, ContentBlock,
 * Campaign, AdAudience) differing only in the per-entity messages and in how deleteEntity()
 * loads/removes the row (e.g. Campaign\Delete also clears the dispatcher's cache tag). A trait,
 * not a shared base class, because each concrete Delete still extends its own entity's
 * AbstractXxxAction to keep that entity's own ADMIN_RESOURCE.
 */
trait DeletesEntityTrait
{
    public function execute()
    {
        $resultRedirect = $this->resultRedirectFactory->create();
        $entityId = (int) $this->getRequest()->getParam('entity_id');

        if (!$entityId) {
            $this->messageManager->addErrorMessage($this->getMissingIdMessage());
            return $resultRedirect->setPath('*/*/');
        }

        try {
            $this->deleteEntity($entityId);
            $this->messageManager->addSuccessMessage($this->getDeletedMessage());
        } catch (\Throwable $e) {
            $this->messageManager->addErrorMessage($this->getDeleteErrorMessage($e));
        }

        return $resultRedirect->setPath('*/*/');
    }

    abstract protected function deleteEntity(int $entityId): void;

    abstract protected function getMissingIdMessage(): Phrase;

    abstract protected function getDeletedMessage(): Phrase;

    abstract protected function getDeleteErrorMessage(\Throwable $e): Phrase;
}
