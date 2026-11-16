<?php

declare(strict_types=1);

namespace Ordo\Automation\Controller\Adminhtml;

use Magento\Backend\Model\View\Result\Page;
use Magento\Framework\Model\AbstractModel;

/**
 * Shared "load-by-id-or-build-new, 404-redirect if the id doesn't resolve, register onto the
 * page, build the edit form page" body - confirmed byte-for-byte identical in shape (only the
 * factory/resource types, registry key, and a handful of noun-phrase strings differ) across 8
 * admin edit controllers: AdAudience, Campaign, ContentBlock, FreeGiftOffer, LeadRoutingRule,
 * ScoreRule, Segment, WhatsAppTemplate. A trait, not a base class, for the same reason as
 * RendersGridPageTrait: each of these already extends its own feature's Abstract*Action (shared
 * with that feature's own Save/Delete/NewAction siblings for ADMIN_RESOURCE), so a shared base
 * class here would force giving that up.
 *
 * missingEntityLabel() and editEntityLabel() are two separate hooks, not one, because the two
 * user-facing strings they feed use different casing by convention - "This ad audience no longer
 * exists." (lowercase noun) vs. "Edit Ad Audience" / "New Ad Audience" (title case) - and
 * deriving one from the other isn't reliable for a two-word brand-prefixed label like "WhatsApp
 * Template" / "WhatsApp template".
 */
trait RendersEditPageTrait
{
    abstract protected function newEntity(): AbstractModel;

    abstract protected function loadEntityById(AbstractModel $entity, int $entityId): void;

    /**
     * @return string the Registry key this entity is published under for the edit form's block
     */
    abstract protected function registryKey(): string;

    /**
     * @return string title-case noun phrase for the page title ("Edit %1 ..."/"New %1")
     */
    abstract protected function editEntityLabel(): string;

    /**
     * @return string lowercase noun phrase for the "This %1 no longer exists." message
     */
    abstract protected function missingEntityLabel(): string;

    abstract protected function entityDisplayName(AbstractModel $entity): string;

    public function execute()
    {
        $entityId = (int) $this->getRequest()->getParam('entity_id');
        $entity = $this->newEntity();

        if ($entityId) {
            $this->loadEntityById($entity, $entityId);
            if (!$entity->getEntityId()) {
                $this->messageManager->addErrorMessage(__('This %1 no longer exists.', $this->missingEntityLabel()));

                return $this->resultRedirectFactory->create()->setPath('*/*/');
            }
        }

        $this->registry->register($this->registryKey(), $entity);

        /** @var Page $resultPage */
        $resultPage = $this->resultPageFactory->create();
        $resultPage->setActiveMenu('Ordo_Automation::campaigns');
        $resultPage->getConfig()->getTitle()->prepend(
            $entityId
                ? __('Edit %1 "%2"', $this->editEntityLabel(), $this->entityDisplayName($entity))
                : __('New %1', $this->editEntityLabel())
        );

        return $resultPage;
    }
}
