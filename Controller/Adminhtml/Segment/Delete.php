<?php
declare(strict_types=1);

namespace Ordo\Automation\Controller\Adminhtml\Segment;

use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Phrase;
use Ordo\Automation\Controller\Adminhtml\Shared\DeletesEntityTrait;
use Ordo\Automation\Model\ResourceModel\Segment as SegmentResource;
use Ordo\Automation\Model\SegmentFactory;

/**
 * Invoked via a POST-with-confirm link (Magento_Ui's "post": true action flag &
 * form-key validation, standard for HttpPostActionInterface controllers) - see
 * Ui\Component\Listing\Column\SegmentActions/AbstractEntityActionsColumn.
 */
class Delete extends AbstractSegmentAction implements HttpPostActionInterface
{
    use DeletesEntityTrait;

    public function __construct(
        Context $context,
        private readonly SegmentFactory $segmentFactory,
        private readonly SegmentResource $segmentResource
    ) {
        parent::__construct($context);
    }

    protected function deleteEntity(int $entityId): void
    {
        $segment = $this->segmentFactory->create();
        $this->segmentResource->load($segment, $entityId);
        // Condition rows cascade-delete via the FK ON DELETE CASCADE in db_schema.xml.
        $this->segmentResource->delete($segment);
    }

    protected function getMissingIdMessage(): Phrase
    {
        return __('Missing segment id.');
    }

    protected function getDeletedMessage(): Phrase
    {
        return __('The segment has been deleted.');
    }

    protected function getDeleteErrorMessage(\Throwable $e): Phrase
    {
        return __('Could not delete the segment: %1', $e->getMessage());
    }
}
