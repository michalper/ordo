<?php
declare(strict_types=1);

namespace Ordo\Automation\Controller\Adminhtml\ContentBlock;

use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Ordo\Automation\Model\ContentBlockFactory;
use Ordo\Automation\Model\ResourceModel\ContentBlock as ContentBlockResource;

class Save extends AbstractContentBlockAction implements HttpPostActionInterface
{
    public function __construct(
        Context $context,
        private readonly ContentBlockFactory $contentBlockFactory,
        private readonly ContentBlockResource $contentBlockResource
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
            $contentBlock = $this->contentBlockFactory->create();
            if ($entityId) {
                $this->contentBlockResource->load($contentBlock, $entityId);
            }

            $type = (string) ($data['type'] ?? '');

            $contentBlock->setIdentifier((string) ($data['identifier'] ?? ''));
            $contentBlock->setName((string) ($data['name'] ?? ''));
            $contentBlock->setType($type);
            $contentBlock->setEnabled((bool) ($data['enabled'] ?? false));
            $contentBlock->setConfigArray($this->buildConfig($type, $data));

            $this->contentBlockResource->save($contentBlock);

            $this->messageManager->addSuccessMessage(__('The content block has been saved.'));

            if ($this->getRequest()->getParam('back')) {
                return $resultRedirect->setPath('*/*/edit', ['entity_id' => $contentBlock->getEntityId()]);
            }

            return $resultRedirect->setPath('*/*/');
        } catch (\Throwable $e) {
            $this->messageManager->addErrorMessage(__('Could not save the content block: %1', $e->getMessage()));
            return $resultRedirect->setPath('*/*/edit', ['entity_id' => $entityId]);
        }
    }

    /**
     * Reconstruct the type-specific "config" JSON from only the fields relevant to the posted
     * type — keeps DataProvider's flat merge and this round-trip symmetric.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function buildConfig(string $type, array $data): array
    {
        return match ($type) {
            'snippet' => [
                'html' => $data['html'] ?? '',
            ],
            'rss' => [
                'feed_url' => $data['feed_url'] ?? '',
                'item_count' => (int) ($data['item_count'] ?? 5),
            ],
            'product_feed' => [
                'source' => $data['source'] ?? 'category',
                'category_id' => $data['category_id'] ?? null,
                'rule_id' => $data['rule_id'] ?? null,
                'item_count' => (int) ($data['item_count'] ?? 4),
            ],
            default => [],
        };
    }
}
