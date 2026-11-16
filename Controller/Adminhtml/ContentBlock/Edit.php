<?php
declare(strict_types=1);

namespace Ordo\Automation\Controller\Adminhtml\ContentBlock;

use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Model\AbstractModel;
use Magento\Framework\Registry;
use Magento\Framework\View\Result\PageFactory;
use Ordo\Automation\Controller\Adminhtml\RendersEditPageTrait;
use Ordo\Automation\Model\ContentBlock;
use Ordo\Automation\Model\ContentBlockFactory;
use Ordo\Automation\Model\ResourceModel\ContentBlock as ContentBlockResource;

class Edit extends AbstractContentBlockAction implements HttpGetActionInterface
{
    use RendersEditPageTrait;

    public function __construct(
        Context $context,
        private readonly PageFactory $resultPageFactory,
        private readonly Registry $registry,
        private readonly ContentBlockFactory $contentBlockFactory,
        private readonly ContentBlockResource $contentBlockResource
    ) {
        parent::__construct($context);
    }

    protected function newEntity(): AbstractModel
    {
        return $this->contentBlockFactory->create();
    }

    protected function loadEntityById(AbstractModel $entity, int $entityId): void
    {
        $this->contentBlockResource->load($entity, $entityId);
    }

    protected function registryKey(): string
    {
        return 'ordo_content_block';
    }

    protected function editEntityLabel(): string
    {
        return 'Content Block';
    }

    protected function missingEntityLabel(): string
    {
        return 'content block';
    }

    protected function entityDisplayName(AbstractModel $entity): string
    {
        /** @var ContentBlock $entity */
        return (string) $entity->getName();
    }
}
