<?php
declare(strict_types=1);

namespace Ordo\Automation\Controller\Adminhtml\AdAudience;

use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Ui\Component\MassAction\Filter;
use Ordo\Automation\Model\ResourceModel\AdAudience as AdAudienceResource;
use Ordo\Automation\Model\ResourceModel\AdAudience\CollectionFactory as AdAudienceCollectionFactory;

/**
 * See MassEnable's own docblock for the shared Filter/collection pattern.
 */
class MassDelete extends AbstractAdAudienceAction implements HttpPostActionInterface
{
    public function __construct(
        Context $context,
        private readonly Filter $filter,
        private readonly AdAudienceCollectionFactory $adAudienceCollectionFactory,
        private readonly AdAudienceResource $adAudienceResource
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $resultRedirect = $this->resultRedirectFactory->create();
        $collection = $this->filter->getCollection($this->adAudienceCollectionFactory->create());

        $count = 0;
        foreach ($collection as $adAudience) {
            /** @var \Ordo\Automation\Model\AdAudience $adAudience */
            $this->adAudienceResource->delete($adAudience);
            $count++;
        }

        $this->messageManager->addSuccessMessage(__('A total of %1 ad audience(s) have been deleted.', $count));

        return $resultRedirect->setPath('*/*/');
    }
}
