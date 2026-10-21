<?php
declare(strict_types=1);

namespace Ordo\Automation\Controller\Adminhtml\PriceWatch;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Phrase;
use Magento\Ui\Component\MassAction\Filter;
use Ordo\Automation\Controller\Adminhtml\Shared\RunsMassActionTrait;
use Ordo\Automation\Model\PriceWatch\PriceWatchSubscription;
use Ordo\Automation\Model\ResourceModel\PriceWatch\PriceWatchSubscription as PriceWatchSubscriptionResource;
use Ordo\Automation\Model\ResourceModel\PriceWatch\PriceWatchSubscription\CollectionFactory as WatchCollectionFactory;

/**
 * Grid mass-delete for the "ids" selectionsColumn — lets an admin remove a stale/unwanted
 * price-watch subscription directly, without waiting for the customer/guest to unsubscribe
 * themselves (there is no storefront unsubscribe flow for this entity today).
 */
class MassDelete extends Action implements HttpPostActionInterface
{
    use RunsMassActionTrait;

    public const ADMIN_RESOURCE = 'Ordo_Automation::price_watch_subscriptions';

    public function __construct(
        Context $context,
        private readonly Filter $filter,
        private readonly WatchCollectionFactory $priceWatchSubscriptionCollectionFactory,
        private readonly PriceWatchSubscriptionResource $priceWatchSubscriptionResource
    ) {
        parent::__construct($context);
    }

    protected function getMassActionCollection(): iterable
    {
        return $this->filter->getCollection($this->priceWatchSubscriptionCollectionFactory->create());
    }

    protected function applyToEntity(object $entity): void
    {
        /** @var PriceWatchSubscription $entity */
        $this->priceWatchSubscriptionResource->delete($entity);
    }

    protected function getMassActionSuccessMessage(int $count): Phrase
    {
        return __('A total of %1 price watch subscription(s) have been deleted.', $count);
    }
}
