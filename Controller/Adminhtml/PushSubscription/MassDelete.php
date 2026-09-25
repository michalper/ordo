<?php
declare(strict_types=1);

namespace Ordo\Automation\Controller\Adminhtml\PushSubscription;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Ui\Component\MassAction\Filter;
use Ordo\Automation\Model\ResourceModel\PushSubscription as PushSubscriptionResource;
use Ordo\Automation\Model\ResourceModel\PushSubscription\CollectionFactory as PushSubscriptionCollectionFactory;

/**
 * Grid mass-delete for the "ids" selectionsColumn — lets an admin remove a stale/unwanted push
 * subscription directly, without waiting for the browser's own unsubscribe
 * (Controller\Track\UnregisterPushSubscription) or for PushSubscriptionSender to prune it after a
 * SubscriptionGoneException.
 */
class MassDelete extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Ordo_Automation::push_subscriptions';

    public function __construct(
        Context $context,
        private readonly Filter $filter,
        private readonly PushSubscriptionCollectionFactory $pushSubscriptionCollectionFactory,
        private readonly PushSubscriptionResource $pushSubscriptionResource
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $resultRedirect = $this->resultRedirectFactory->create();
        $collection = $this->filter->getCollection($this->pushSubscriptionCollectionFactory->create());

        $count = 0;
        foreach ($collection as $subscription) {
            /** @var \Ordo\Automation\Model\PushSubscription $subscription */
            $this->pushSubscriptionResource->delete($subscription);
            $count++;
        }

        $this->messageManager->addSuccessMessage(__('A total of %1 push subscription(s) have been deleted.', $count));

        return $resultRedirect->setPath('*/*/');
    }
}
