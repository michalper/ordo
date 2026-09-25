<?php
declare(strict_types=1);

namespace Ordo\Automation\Controller\Adminhtml\PriceWatch;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Ui\Component\MassAction\Filter;
use Ordo\Automation\Model\ResourceModel\PriceWatch\PriceWatchSubscription as PriceWatchSubscriptionResource;
use Ordo\Automation\Model\ResourceModel\PriceWatch\PriceWatchSubscription\CollectionFactory as PriceWatchSubscriptionCollectionFactory;

/**
 * Grid mass-delete for the "ids" selectionsColumn — lets an admin remove a stale/unwanted
 * price-watch subscription directly, without waiting for the customer/guest to unsubscribe
 * themselves (there is no storefront unsubscribe flow for this entity today).
 */
class MassDelete extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Ordo_Automation::price_watch_subscriptions';

    public function __construct(
        Context $context,
        private readonly Filter $filter,
        private readonly PriceWatchSubscriptionCollectionFactory $priceWatchSubscriptionCollectionFactory,
        private readonly PriceWatchSubscriptionResource $priceWatchSubscriptionResource
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $resultRedirect = $this->resultRedirectFactory->create();
        $collection = $this->filter->getCollection($this->priceWatchSubscriptionCollectionFactory->create());

        $count = 0;
        foreach ($collection as $subscription) {
            /** @var \Ordo\Automation\Model\PriceWatch\PriceWatchSubscription $subscription */
            $this->priceWatchSubscriptionResource->delete($subscription);
            $count++;
        }

        $this->messageManager->addSuccessMessage(
            __('A total of %1 price watch subscription(s) have been deleted.', $count)
        );

        return $resultRedirect->setPath('*/*/');
    }
}
