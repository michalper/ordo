<?php
declare(strict_types=1);

namespace Ordo\Automation\Controller\Adminhtml\ReorderCycle;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Ordo\Automation\Cron\CalculateReorderCycle;

/**
 * On-demand "recalculate now" for reorder cycles — synchronous, unlike Cron\CalculateReorderCycle's
 * own nightly schedule. Same pattern Controller\Adminhtml\ProductFeed\RefreshNow already
 * established for the shopping feed cron: an admin who just wants to see today's order history
 * reflected immediately (e.g. right after fixing a data issue) doesn't have to wait for the next
 * cron tick. Closes the ROADMAP.md gap flagged against this exact inconsistency — segments got
 * this same on-demand-refresh treatment (Controller\Adminhtml\Segment\AudienceSize) but reorder
 * cycles hadn't, despite being the same "cached, periodically-recalculated metric" shape.
 */
class RecalculateNow extends Action implements HttpGetActionInterface
{
    // Same resource Controller\Adminhtml\ReorderCycle\Index already gates on - see that
    // controller's own docblock for why campaigns, not config.
    public const ADMIN_RESOURCE = 'Ordo_Automation::campaigns';

    public function __construct(
        Context $context,
        private readonly CalculateReorderCycle $calculateReorderCycle
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $resultRedirect = $this->resultRedirectFactory->create();
        $resultRedirect->setPath('ordo/reordercycle/index');

        try {
            $processed = $this->calculateReorderCycle->execute();
            $this->messageManager->addSuccessMessage(
                __('Reorder cycles recalculated: %1 pair(s).', $processed)
            );
        } catch (\Throwable $e) {
            $this->messageManager->addErrorMessage(__('Reorder cycle recalculation failed: %1', $e->getMessage()));
        }

        return $resultRedirect;
    }
}
