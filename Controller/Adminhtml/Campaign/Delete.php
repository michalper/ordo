<?php
declare(strict_types=1);

namespace Ordo\Automation\Controller\Adminhtml\Campaign;

use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\Phrase;
use Ordo\Automation\Controller\Adminhtml\Shared\DeletesEntityTrait;
use Ordo\Automation\Model\CampaignDispatcher;
use Ordo\Automation\Model\CampaignFactory;
use Ordo\Automation\Model\ResourceModel\Campaign as CampaignResource;

/**
 * Invoked via a POST-with-confirm link (Magento_Ui's "post": true action flag &
 * form-key validation, standard for HttpPostActionInterface controllers) - see
 * Ui\Component\Listing\Column\CampaignActions/AbstractEntityActionsColumn.
 */
class Delete extends AbstractCampaignAction implements HttpPostActionInterface
{
    use DeletesEntityTrait;

    public function __construct(
        Context $context,
        private readonly CampaignFactory $campaignFactory,
        private readonly CampaignResource $campaignResource,
        private readonly CacheInterface $cache
    ) {
        parent::__construct($context);
    }

    protected function deleteEntity(int $entityId): void
    {
        $campaign = $this->campaignFactory->create();
        $this->campaignResource->load($campaign, $entityId);
        // Condition/action rows cascade-delete via the FK ON DELETE CASCADE in db_schema.xml.
        $this->campaignResource->delete($campaign);
        $this->cache->clean([CampaignDispatcher::CACHE_TAG]);
    }

    protected function getMissingIdMessage(): Phrase
    {
        return __('Missing campaign id.');
    }

    protected function getDeletedMessage(): Phrase
    {
        return __('The campaign has been deleted.');
    }

    protected function getDeleteErrorMessage(\Throwable $e): Phrase
    {
        return __('Could not delete the campaign: %1', $e->getMessage());
    }
}
