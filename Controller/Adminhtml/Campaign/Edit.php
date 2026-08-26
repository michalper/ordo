<?php
declare(strict_types=1);

namespace Ordo\Automation\Controller\Adminhtml\Campaign;

use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Registry;
use Magento\Framework\View\Result\PageFactory;
use Ordo\Automation\Model\CampaignFactory;
use Ordo\Automation\Model\ResourceModel\Campaign as CampaignResource;

class Edit extends AbstractCampaignAction implements HttpGetActionInterface
{
    public function __construct(
        Context $context,
        private readonly PageFactory $resultPageFactory,
        private readonly Registry $registry,
        private readonly CampaignFactory $campaignFactory,
        private readonly CampaignResource $campaignResource
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $entityId = (int) $this->getRequest()->getParam('entity_id');
        $campaign = $this->campaignFactory->create();

        if ($entityId) {
            $this->campaignResource->load($campaign, $entityId);
            if (!$campaign->getEntityId()) {
                $this->messageManager->addErrorMessage(__('This campaign no longer exists.'));
                return $this->resultRedirectFactory->create()->setPath('*/*/');
            }
        }

        $this->registry->register('ordo_campaign', $campaign);

        $resultPage = $this->resultPageFactory->create();
        $resultPage->setActiveMenu('Ordo_Automation::campaigns');
        $resultPage->getConfig()->getTitle()->prepend(
            $entityId ? __('Edit Campaign "%1"', $campaign->getName()) : __('New Campaign')
        );

        return $resultPage;
    }
}
