<?php
declare(strict_types=1);

namespace Ordo\Automation\Controller\Adminhtml\MessageLog;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Ui\Component\MassAction\Filter;
use Ordo\Automation\Model\ResourceModel\MessageLog as MessageLogResource;
use Ordo\Automation\Model\ResourceModel\MessageLog\CollectionFactory as MessageLogCollectionFactory;

/**
 * Mass-delete for the "ids" selectionsColumn this grid's checkboxes render — closes the
 * ROADMAP.md admin-platform gap noting MessageLog had no mass action of any kind. Unlike
 * Controller\Adminhtml\WhatsAppTemplate\MassDelete, this grid has no per-row actions column to
 * begin with (it's a read-only delivery log — Model\Sms\MessageLogWriter and friends are the
 * only writers, there was never a single-row delete to wire a mass counterpart onto), so
 * mass-delete is the entirety of what this pass adds here. Same
 * Ui\Component\MassAction\Filter + CollectionFactory + resource-delete-per-row pattern as every
 * other MassDelete in this module.
 */
class MassDelete extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Ordo_Automation::message_log';

    public function __construct(
        Context $context,
        private readonly Filter $filter,
        private readonly MessageLogCollectionFactory $messageLogCollectionFactory,
        private readonly MessageLogResource $messageLogResource
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $resultRedirect = $this->resultRedirectFactory->create();
        $collection = $this->filter->getCollection($this->messageLogCollectionFactory->create());

        $count = 0;
        foreach ($collection as $messageLog) {
            /** @var \Ordo\Automation\Model\MessageLog $messageLog */
            $this->messageLogResource->delete($messageLog);
            $count++;
        }

        $this->messageManager->addSuccessMessage(__('A total of %1 message log entry(s) have been deleted.', $count));

        return $resultRedirect->setPath('*/*/');
    }
}
