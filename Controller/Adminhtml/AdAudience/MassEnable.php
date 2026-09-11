<?php
declare(strict_types=1);

namespace Ordo\Automation\Controller\Adminhtml\AdAudience;

use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Ui\Component\MassAction\Filter;
use Ordo\Automation\Model\ResourceModel\AdAudience as AdAudienceResource;
use Ordo\Automation\Model\ResourceModel\AdAudience\CollectionFactory as AdAudienceCollectionFactory;

/**
 * Grid mass-action counterpart to the single-audience enable/disable toggle already available
 * from Ad Audience Edit — closes the admin-platform ROADMAP.md gap where this grid's own
 * selectionsColumn rendered checkboxes with no massaction behind them. Same
 * Ui\Component\MassAction\Filter pattern as Controller\Adminhtml\Campaign\MassEnable, adapted to
 * this entity's plain Factory + ResourceModel::save() (AdAudience has no repository interface).
 */
class MassEnable extends AbstractAdAudienceAction implements HttpPostActionInterface
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
            $adAudience->setEnabled(true);
            $this->adAudienceResource->save($adAudience);
            $count++;
        }

        $this->messageManager->addSuccessMessage(__('A total of %1 ad audience(s) have been enabled.', $count));

        return $resultRedirect->setPath('*/*/');
    }
}
