<?php
declare(strict_types=1);

namespace Ordo\Automation\Controller\Adminhtml\CronRunLog;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\View\Result\PageFactory;

/**
 * Read-only grid over ordo_cron_run_log (Model\Cron\CronRunLogger) - "did today's escalation cron
 * even run" is no longer invisible without log-tailing.
 */
class Index extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'Ordo_Automation::cron_run_log';

    public function __construct(
        Context $context,
        private readonly PageFactory $resultPageFactory
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $resultPage = $this->resultPageFactory->create();
        $resultPage->setActiveMenu('Ordo_Automation::campaigns');
        $resultPage->getConfig()->getTitle()->prepend(__('Cron Run Log'));

        return $resultPage;
    }
}
