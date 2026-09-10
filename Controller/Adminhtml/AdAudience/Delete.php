<?php
declare(strict_types=1);

namespace Ordo\Automation\Controller\Adminhtml\AdAudience;

use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Ordo\Automation\Model\AdAudienceFactory;
use Ordo\Automation\Model\ResourceModel\AdAudience as AdAudienceResource;

/**
 * Invoked via a POST-with-confirm link (Magento_Ui's "post": true action flag &
 * form-key validation, standard for HttpPostActionInterface controllers) - see
 * Ui\Component\Listing\Column\AdAudienceActions/AbstractEntityActionsColumn.
 */
class Delete extends AbstractAdAudienceAction implements HttpPostActionInterface
{
    public function __construct(
        Context $context,
        private readonly AdAudienceFactory $adAudienceFactory,
        private readonly AdAudienceResource $adAudienceResource
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $resultRedirect = $this->resultRedirectFactory->create();
        $entityId = (int) $this->getRequest()->getParam('entity_id');

        if (!$entityId) {
            $this->messageManager->addErrorMessage(__('Missing ad audience id.'));
            return $resultRedirect->setPath('*/*/');
        }

        try {
            $adAudience = $this->adAudienceFactory->create();
            $this->adAudienceResource->load($adAudience, $entityId);
            $this->adAudienceResource->delete($adAudience);

            $this->messageManager->addSuccessMessage(__('The ad audience has been deleted.'));
        } catch (\Throwable $e) {
            $this->messageManager->addErrorMessage(__('Could not delete the ad audience: %1', $e->getMessage()));
        }

        return $resultRedirect->setPath('*/*/');
    }
}
