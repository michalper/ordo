<?php
declare(strict_types=1);

namespace Ordo\Automation\Controller\Adminhtml\ReorderCycle;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Ordo\Automation\Model\ReorderCycle\OptedOutException;
use Ordo\Automation\Model\ReorderCycle\ReorderReminderSender;
use Ordo\Automation\Model\ReorderCycleFactory;
use Ordo\Automation\Model\ResourceModel\ReorderCycle as ReorderCycleResource;

/**
 * On-demand "send reminder now" for one reorder cycle row - closes the ROADMAP.md "no manual
 * per-customer reminder trigger" gap. Same on-demand-action shape as this controller's own
 * sibling RecalculateNow, POST (not GET) since this one has a real side effect (an email sent to
 * a customer), unlike a read-only recalculation.
 */
class SendReminder extends Action implements HttpPostActionInterface
{
    // Same resource Controller\Adminhtml\ReorderCycle\Index already gates on.
    public const ADMIN_RESOURCE = 'Ordo_Automation::reorder_cycle';

    public function __construct(
        Context $context,
        private readonly ReorderCycleFactory $reorderCycleFactory,
        private readonly ReorderCycleResource $reorderCycleResource,
        private readonly CustomerRepositoryInterface $customerRepository,
        private readonly ReorderReminderSender $reminderSender
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $resultRedirect = $this->resultRedirectFactory->create();
        $resultRedirect->setPath('ordo/reordercycle/index');

        $entityId = (int) $this->getRequest()->getParam('entity_id');
        if (!$entityId) {
            $this->messageManager->addErrorMessage(__('Missing reorder cycle id.'));
            return $resultRedirect;
        }

        $cycle = $this->reorderCycleFactory->create();
        $this->reorderCycleResource->load($cycle, $entityId);

        if (!$cycle->getEntityId()) {
            $this->messageManager->addErrorMessage(__('That reorder cycle no longer exists.'));
            return $resultRedirect;
        }

        try {
            $customer = $this->customerRepository->getById($cycle->getCustomerId());
            $this->reminderSender->sendNow($cycle, $customer);
            $this->messageManager->addSuccessMessage(
                __('Reorder reminder sent to %1.', $customer->getEmail())
            );
        } catch (NoSuchEntityException) {
            $this->messageManager->addErrorMessage(__('That customer no longer exists.'));
        } catch (OptedOutException) {
            $this->messageManager->addErrorMessage(
                __('This customer has opted out of email - reminder not sent.')
            );
        } catch (\Throwable $e) {
            $this->messageManager->addErrorMessage(__('Could not send the reminder: %1', $e->getMessage()));
        }

        return $resultRedirect;
    }
}
