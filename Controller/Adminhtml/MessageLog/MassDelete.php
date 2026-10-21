<?php
declare(strict_types=1);

namespace Ordo\Automation\Controller\Adminhtml\MessageLog;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Phrase;
use Magento\Ui\Component\MassAction\Filter;
use Ordo\Automation\Controller\Adminhtml\Shared\RunsMassActionTrait;
use Ordo\Automation\Model\MessageLog;
use Ordo\Automation\Model\ResourceModel\MessageLog as MessageLogResource;
use Ordo\Automation\Model\ResourceModel\MessageLog\CollectionFactory as MessageLogCollectionFactory;

/**
 * Mass-delete for the "ids" selectionsColumn this grid's checkboxes render — closes the
 * ROADMAP.md admin-platform gap noting MessageLog had no mass action of any kind. Unlike
 * Controller\Adminhtml\WhatsAppTemplate\MassDelete, this grid has no per-row actions column to
 * begin with (it's a read-only delivery log — Model\Sms\MessageLogWriter and friends are the
 * only writers, there was never a single-row delete to wire a mass counterpart onto), so
 * mass-delete is the entirety of what this pass adds here. Same
 * Ui\Component\MassAction\Filter + CollectionFactory + resource-delete-per-row pattern as every
 * other MassDelete in this module.
 */
class MassDelete extends Action implements HttpPostActionInterface
{
    use RunsMassActionTrait;

    public const ADMIN_RESOURCE = 'Ordo_Automation::message_log';

    public function __construct(
        Context $context,
        private readonly Filter $filter,
        private readonly MessageLogCollectionFactory $messageLogCollectionFactory,
        private readonly MessageLogResource $messageLogResource
    ) {
        parent::__construct($context);
    }

    protected function getMassActionCollection(): iterable
    {
        return $this->filter->getCollection($this->messageLogCollectionFactory->create());
    }

    protected function applyToEntity(object $entity): void
    {
        /** @var MessageLog $entity */
        $this->messageLogResource->delete($entity);
    }

    protected function getMassActionSuccessMessage(int $count): Phrase
    {
        return __('A total of %1 message log entry(s) have been deleted.', $count);
    }
}
