<?php
declare(strict_types=1);

namespace Ordo\Automation\Controller\Adminhtml\Campaign;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\View\Result\Page;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\View\Result\PageFactory;

/**
 * Read-only overview of every enabled campaign's trigger(s) and its action chain's timing
 * (delay_minutes between steps) in one place — no new entities, this reads the same
 * ordo_campaign/ordo_campaign_trigger/ordo_campaign_action tables the edit form and dispatcher
 * already use (see Block\Adminhtml\Campaign\Calendar\CampaignCalendarViewModel).
 */
class Calendar extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'Ordo_Automation::campaigns';

    public function __construct(
        Context $context,
        private readonly PageFactory $resultPageFactory
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        /** @var Page $resultPage */
        $resultPage = $this->resultPageFactory->create();
        $resultPage->setActiveMenu('Ordo_Automation::top_level');
        // Renamed from "Campaign Calendar" - reported directly, correctly: this shows relative
        // trigger->action delay offsets ("+1440 min"), not calendar dates - trigger-based
        // campaigns fire on customer events (order placed, cart abandoned), not fixed schedule
        // times, so there are no dates to put on an actual calendar. Controller/route/class
        // names kept as-is (Calendar.php, ordo/campaign/calendar) to avoid a breaking URL
        // change for an existing bookmark/menu entry - only the user-facing label changed.
        $resultPage->getConfig()->getTitle()->prepend(__('Campaign Action Timeline'));

        return $resultPage;
    }
}
