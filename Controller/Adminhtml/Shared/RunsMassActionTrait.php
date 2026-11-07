<?php
declare(strict_types=1);

namespace Ordo\Automation\Controller\Adminhtml\Shared;

use Magento\Framework\Phrase;

/**
 * Shared execute() body for every entity's grid mass-action controller (MassEnable, MassDisable,
 * MassDelete) — was twenty-one byte-identical copies across seven entities (Segment, ScoreRule,
 * LeadRoutingRule, FreeGiftOffer, ContentBlock, Campaign, AdAudience) differing only in the
 * per-entity message and in how applyToEntity() persists the row (most save through a plain
 * ResourceModel; Campaign saves/deletes through CampaignRepositoryInterface instead). A trait,
 * not a shared base class, so each concrete controller still extends its own entity's
 * AbstractXxxAction to keep that entity's own ADMIN_RESOURCE.
 */
trait RunsMassActionTrait
{
    public function execute()
    {
        $resultRedirect = $this->resultRedirectFactory->create();

        $count = 0;
        $failures = 0;
        foreach ($this->getMassActionCollection() as $entity) {
            try {
                $this->applyToEntity($entity);
                $count++;
            } catch (\Throwable $e) {
                // Same catch-and-continue shape as the sibling DeletesEntityTrait - one row
                // throwing (an FK constraint, a repository's CouldNotDeleteException because
                // that row has a dependent record) must not silently abort every other selected
                // row still left in the loop, nor leave the admin with no message at all about
                // what happened.
                $failures++;
            }
        }

        $this->messageManager->addSuccessMessage($this->getMassActionSuccessMessage($count));
        if ($failures > 0) {
            $this->messageManager->addErrorMessage(__('%1 item(s) could not be processed.', $failures));
        }

        return $resultRedirect->setPath('*/*/');
    }

    /**
     * @return iterable<object>
     */
    abstract protected function getMassActionCollection(): iterable;

    abstract protected function applyToEntity(object $entity): void;

    abstract protected function getMassActionSuccessMessage(int $count): Phrase;
}
