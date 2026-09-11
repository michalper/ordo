<?php
declare(strict_types=1);

namespace Ordo\Automation\Controller\Adminhtml\ReorderCycle;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Ordo\Automation\Model\ReorderCycle\ReorderCartBuilder;
use Ordo\Automation\Model\ReorderCycleFactory;
use Ordo\Automation\Model\ResourceModel\ReorderCycle as ReorderCycleResource;

/**
 * On-demand "build a cart for this customer with this product" for one reorder cycle row -
 * closes the ROADMAP.md "no one-click build reorder cart action" gap (SendReminder only ever
 * nudges the customer by email; this actually starts an order an admin can finish placing).
 * Model\ReorderCycle\ReorderCartBuilder does the actual cart-building; on success this redirects
 * straight into Magento's own "Create New Order" screen with the cart already populated.
 */
class BuildCart extends Action implements HttpPostActionInterface
{
    // Same resource Controller\Adminhtml\ReorderCycle\Index already gates on.
    public const ADMIN_RESOURCE = 'Ordo_Automation::reorder_cycle';

    public function __construct(
        Context $context,
        private readonly ReorderCycleFactory $reorderCycleFactory,
        private readonly ReorderCycleResource $reorderCycleResource,
        private readonly CustomerRepositoryInterface $customerRepository,
        private readonly ReorderCartBuilder $reorderCartBuilder
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
            $this->reorderCartBuilder->build($cycle, $customer);
        } catch (NoSuchEntityException $e) {
            $this->messageManager->addErrorMessage(
                __('Could not build the cart: %1', $e->getMessage())
            );
            return $resultRedirect;
        } catch (LocalizedException $e) {
            $this->messageManager->addErrorMessage(__('Could not build the cart: %1', $e->getMessage()));
            return $resultRedirect;
        }

        $this->messageManager->addSuccessMessage(
            __('Cart built for this customer - review and place the order below.')
        );

        return $resultRedirect->setPath('sales/order_create/index');
    }
}
