<?php
declare(strict_types=1);

namespace Ordo\Automation\Controller\Adminhtml\EmailTemplateDraft;

use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Phrase;
use Magento\Ui\Component\MassAction\Filter;
use Ordo\Automation\Controller\Adminhtml\Shared\RunsMassActionTrait;
use Ordo\Automation\Model\EmailTemplateDraft;
use Ordo\Automation\Model\ResourceModel\EmailTemplateDraft as EmailTemplateDraftResource;
use Ordo\Automation\Model\ResourceModel\EmailTemplateDraft\CollectionFactory as EmailTemplateDraftCollectionFactory;

class MassDelete extends AbstractEmailTemplateDraftAction implements HttpPostActionInterface
{
    use RunsMassActionTrait;

    public function __construct(
        Context $context,
        private readonly Filter $filter,
        private readonly EmailTemplateDraftCollectionFactory $draftCollectionFactory,
        private readonly EmailTemplateDraftResource $draftResource
    ) {
        parent::__construct($context);
    }

    protected function getMassActionCollection(): iterable
    {
        return $this->filter->getCollection($this->draftCollectionFactory->create());
    }

    protected function applyToEntity(object $entity): void
    {
        /** @var EmailTemplateDraft $entity */
        $this->draftResource->delete($entity);
    }

    protected function getMassActionSuccessMessage(int $count): Phrase
    {
        return __('A total of %1 email template draft(s) have been deleted.', $count);
    }
}
