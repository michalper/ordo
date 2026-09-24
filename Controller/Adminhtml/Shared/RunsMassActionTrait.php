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
        foreach ($this->getMassActionCollection() as $entity) {
            $this->applyToEntity($entity);
            $count++;
        }

        $this->messageManager->addSuccessMessage($this->getMassActionSuccessMessage($count));

        return $resultRedirect->setPath('*/*/');
    }

    /**
     * @return iterable<object>
     */
    abstract protected function getMassActionCollection(): iterable;

    abstract protected function applyToEntity(object $entity): void;

    abstract protected function getMassActionSuccessMessage(int $count): Phrase;
}
