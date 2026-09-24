<?php
declare(strict_types=1);

namespace Ordo\Automation\Controller\Adminhtml\Campaign;

use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Phrase;
use Magento\Ui\Component\MassAction\Filter;
use Ordo\Automation\Api\CampaignRepositoryInterface;
use Ordo\Automation\Controller\Adminhtml\Shared\RunsMassActionTrait;
use Ordo\Automation\Model\Campaign;
use Ordo\Automation\Model\ResourceModel\Campaign\CollectionFactory as CampaignCollectionFactory;

/**
 * See MassEnable's own docblock for the shared Filter/collection pattern. Deletes through
 * CampaignRepositoryInterface (a service contract) rather than calling Campaign::delete()
 * directly — its own delete() already flushes exactly the per-trigger-event cache tags this
 * campaign's trigger rows were tied to (see CampaignRepository::delete()'s own comments), so
 * there's nothing extra to clean up here.
 */
class MassDelete extends AbstractCampaignAction implements HttpPostActionInterface
{
    use RunsMassActionTrait;

    public function __construct(
        Context $context,
        private readonly Filter $filter,
        private readonly CampaignCollectionFactory $campaignCollectionFactory,
        private readonly CampaignRepositoryInterface $campaignRepository
    ) {
        parent::__construct($context);
    }

    protected function getMassActionCollection(): iterable
    {
        return $this->filter->getCollection($this->campaignCollectionFactory->create());
    }

    protected function applyToEntity(object $entity): void
    {
        /** @var Campaign $entity */
        // Condition/action rows cascade-delete via the FK ON DELETE CASCADE in db_schema.xml.
        $this->campaignRepository->delete($entity);
    }

    protected function getMassActionSuccessMessage(int $count): Phrase
    {
        return __('A total of %1 campaign(s) have been deleted.', $count);
    }
}
