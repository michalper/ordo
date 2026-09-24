<?php
declare(strict_types=1);

namespace Ordo\Automation\Controller\Adminhtml\Segment;

use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Phrase;
use Magento\Ui\Component\MassAction\Filter;
use Ordo\Automation\Controller\Adminhtml\Shared\RunsMassActionTrait;
use Ordo\Automation\Model\ResourceModel\Segment as SegmentResource;
use Ordo\Automation\Model\ResourceModel\Segment\CollectionFactory as SegmentCollectionFactory;
use Ordo\Automation\Model\Segment;

/**
 * Grid mass-action counterpart to the single-segment enable/disable toggle already available
 * from Segment Edit - see Campaign\MassEnable's own docblock for the shared
 * Ui\Component\MassAction\Filter pattern this mirrors. Segments have no repository (unlike
 * Campaign) - saves through SegmentResource directly, the same way Segment\Save/Delete already
 * do, rather than through Segment::save() itself.
 */
class MassEnable extends AbstractSegmentAction implements HttpPostActionInterface
{
    use RunsMassActionTrait;

    public function __construct(
        Context $context,
        private readonly Filter $filter,
        private readonly SegmentCollectionFactory $segmentCollectionFactory,
        private readonly SegmentResource $segmentResource
    ) {
        parent::__construct($context);
    }

    protected function getMassActionCollection(): iterable
    {
        return $this->filter->getCollection($this->segmentCollectionFactory->create());
    }

    protected function applyToEntity(object $entity): void
    {
        /** @var Segment $entity */
        $entity->setEnabled(true);
        $this->segmentResource->save($entity);
    }

    protected function getMassActionSuccessMessage(int $count): Phrase
    {
        return __('A total of %1 segment(s) have been enabled.', $count);
    }
}
