<?php
declare(strict_types=1);

namespace Ordo\Automation\Controller\Adminhtml\Segment;

use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Ordo\Automation\Model\Segment\SegmentSaveProcessor;

/**
 * Persists the segment row plus its condition child rows in one request. The actual
 * persistence logic lives in SegmentSaveProcessor — this controller only handles the HTTP
 * request/response, redirects, and admin messages.
 */
class Save extends AbstractSegmentAction implements HttpPostActionInterface
{
    public function __construct(
        Context $context,
        private readonly SegmentSaveProcessor $saveProcessor
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        /** @var array<string, mixed> $data */
        $data = $this->getRequest()->getPostValue();
        $resultRedirect = $this->resultRedirectFactory->create();

        if (!$data) {
            return $resultRedirect->setPath('*/*/');
        }

        $entityId = (int) ($data['entity_id'] ?? 0);

        try {
            $segment = $this->saveProcessor->process($data);

            $this->messageManager->addSuccessMessage(__('The segment has been saved.'));

            if ($this->getRequest()->getParam('back')) {
                return $resultRedirect->setPath('*/*/edit', ['entity_id' => $segment->getEntityId()]);
            }

            return $resultRedirect->setPath('*/*/');
        } catch (\Throwable $e) {
            $this->messageManager->addErrorMessage(__('Could not save the segment: %1', $e->getMessage()));
            return $resultRedirect->setPath('*/*/edit', ['entity_id' => $entityId]);
        }
    }
}
