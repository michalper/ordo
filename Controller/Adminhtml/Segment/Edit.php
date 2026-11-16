<?php
declare(strict_types=1);

namespace Ordo\Automation\Controller\Adminhtml\Segment;

use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Model\AbstractModel;
use Magento\Framework\Registry;
use Magento\Framework\View\Result\PageFactory;
use Ordo\Automation\Controller\Adminhtml\RendersEditPageTrait;
use Ordo\Automation\Model\ResourceModel\Segment as SegmentResource;
use Ordo\Automation\Model\Segment;
use Ordo\Automation\Model\SegmentFactory;

class Edit extends AbstractSegmentAction implements HttpGetActionInterface
{
    use RendersEditPageTrait;

    public function __construct(
        Context $context,
        private readonly PageFactory $resultPageFactory,
        private readonly Registry $registry,
        private readonly SegmentFactory $segmentFactory,
        private readonly SegmentResource $segmentResource
    ) {
        parent::__construct($context);
    }

    protected function newEntity(): AbstractModel
    {
        return $this->segmentFactory->create();
    }

    protected function loadEntityById(AbstractModel $entity, int $entityId): void
    {
        $this->segmentResource->load($entity, $entityId);
    }

    protected function registryKey(): string
    {
        return 'ordo_segment';
    }

    protected function editEntityLabel(): string
    {
        return 'Segment';
    }

    protected function missingEntityLabel(): string
    {
        return 'segment';
    }

    protected function entityDisplayName(AbstractModel $entity): string
    {
        /** @var Segment $entity */
        return (string) $entity->getName();
    }
}
