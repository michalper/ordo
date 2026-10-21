<?php
declare(strict_types=1);

namespace Ordo\Automation\Controller\Adminhtml\PushSubscription;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Phrase;
use Magento\Ui\Component\MassAction\Filter;
use Ordo\Automation\Controller\Adminhtml\Shared\RunsMassActionTrait;
use Ordo\Automation\Model\PushSubscription;
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
    use RunsMassActionTrait;

    public const ADMIN_RESOURCE = 'Ordo_Automation::push_subscriptions';

    public function __construct(
        Context $context,
        private readonly Filter $filter,
        private readonly PushSubscriptionCollectionFactory $pushSubscriptionCollectionFactory,
        private readonly PushSubscriptionResource $pushSubscriptionResource
    ) {
        parent::__construct($context);
    }

    protected function getMassActionCollection(): iterable
    {
        return $this->filter->getCollection($this->pushSubscriptionCollectionFactory->create());
    }

    protected function applyToEntity(object $entity): void
    {
        /** @var PushSubscription $entity */
        $this->pushSubscriptionResource->delete($entity);
    }

    protected function getMassActionSuccessMessage(int $count): Phrase
    {
        return __('A total of %1 push subscription(s) have been deleted.', $count);
    }
}
