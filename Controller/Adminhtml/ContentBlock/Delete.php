<?php
declare(strict_types=1);

namespace Ordo\Automation\Controller\Adminhtml\ContentBlock;

use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Phrase;
use Ordo\Automation\Controller\Adminhtml\Shared\DeletesEntityTrait;
use Ordo\Automation\Model\ContentBlockFactory;
use Ordo\Automation\Model\ResourceModel\ContentBlock as ContentBlockResource;

/**
 * Invoked via a POST-with-confirm link (Magento_Ui's "post": true action flag &
 * form-key validation, standard for HttpPostActionInterface controllers) - see
 * Ui\Component\Listing\Column\ContentBlockActions/AbstractEntityActionsColumn.
 */
class Delete extends AbstractContentBlockAction implements HttpPostActionInterface
{
    use DeletesEntityTrait;

    public function __construct(
        Context $context,
        private readonly ContentBlockFactory $contentBlockFactory,
        private readonly ContentBlockResource $contentBlockResource
    ) {
        parent::__construct($context);
    }

    protected function deleteEntity(int $entityId): void
    {
        $contentBlock = $this->contentBlockFactory->create();
        $this->contentBlockResource->load($contentBlock, $entityId);
        $this->contentBlockResource->delete($contentBlock);
    }

    protected function getMissingIdMessage(): Phrase
    {
        return __('Missing content block id.');
    }

    protected function getDeletedMessage(): Phrase
    {
        return __('The content block has been deleted.');
    }

    protected function getDeleteErrorMessage(\Throwable $e): Phrase
    {
        return __('Could not delete the content block: %1', $e->getMessage());
    }
}
