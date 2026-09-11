<?php
declare(strict_types=1);

namespace Ordo\Automation\Controller\Adminhtml\ReorderCycle;

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
class SendReminder extends AbstractReorderCycleAction implements HttpPostActionInterface
{
    public function __construct(
        Context $context,
        ReorderCycleFactory $reorderCycleFactory,
        ReorderCycleResource $reorderCycleResource,
        private readonly CustomerRepositoryInterface $customerRepository,
        private readonly ReorderReminderSender $reminderSender
    ) {
        parent::__construct($context, $reorderCycleFactory, $reorderCycleResource);
    }

    public function execute()
    {
        $resultRedirect = $this->resultRedirectFactory->create();
        $resultRedirect->setPath('ordo/reordercycle/index');

        $cycle = $this->loadCycleOrFail();
        if (!$cycle instanceof \Ordo\Automation\Model\ReorderCycle) {
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
