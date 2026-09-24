<?php
declare(strict_types=1);

namespace Ordo\Automation\Controller\Adminhtml\AdAudience;

use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Phrase;
use Ordo\Automation\Controller\Adminhtml\Shared\DeletesEntityTrait;
use Ordo\Automation\Model\AdAudienceFactory;
use Ordo\Automation\Model\ResourceModel\AdAudience as AdAudienceResource;

/**
 * Invoked via a POST-with-confirm link (Magento_Ui's "post": true action flag &
 * form-key validation, standard for HttpPostActionInterface controllers) - see
 * Ui\Component\Listing\Column\AdAudienceActions/AbstractEntityActionsColumn.
 */
class Delete extends AbstractAdAudienceAction implements HttpPostActionInterface
{
    use DeletesEntityTrait;

    public function __construct(
        Context $context,
        private readonly AdAudienceFactory $adAudienceFactory,
        private readonly AdAudienceResource $adAudienceResource
    ) {
        parent::__construct($context);
    }

    protected function deleteEntity(int $entityId): void
    {
        $adAudience = $this->adAudienceFactory->create();
        $this->adAudienceResource->load($adAudience, $entityId);
        $this->adAudienceResource->delete($adAudience);
    }

    protected function getMissingIdMessage(): Phrase
    {
        return __('Missing ad audience id.');
    }

    protected function getDeletedMessage(): Phrase
    {
        return __('The ad audience has been deleted.');
    }

    protected function getDeleteErrorMessage(\Throwable $e): Phrase
    {
        return __('Could not delete the ad audience: %1', $e->getMessage());
    }
}
