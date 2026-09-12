<?php
declare(strict_types=1);

namespace Ordo\Automation\Controller\Adminhtml\Campaign;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\View\Result\PageFactory;

/**
 * Read-only date-grid calendar of every enabled campaign's `scheduled_at`/`recurring_schedule`
 * trigger(s) (see Block\Adminhtml\Campaign\ScheduleCalendar\CampaignScheduleCalendarViewModel) —
 * the ROADMAP.md "Scheduled (date-based) campaigns: calendar view" item. Kept as its own screen
 * rather than a view-toggle on "Campaign Action Timeline" (ordo/campaign/calendar): that page is
 * a plain server-rendered list with no view-switching mechanism, and (per its own controller's
 * comment) was deliberately renamed away from calendar/date framing because most campaigns don't
 * fire on fixed dates at all — only scheduled_at/recurring_schedule ones do, so a real calendar
 * only makes sense as a page scoped to that subset.
 */
class ScheduleCalendar extends Action implements HttpGetActionInterface
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
        $resultPage = $this->resultPageFactory->create();
        $resultPage->setActiveMenu('Ordo_Automation::top_level');
        $resultPage->getConfig()->getTitle()->prepend(__('Scheduled Campaign Calendar'));

        return $resultPage;
    }
}
