<?php
declare(strict_types=1);

namespace Ordo\Automation\Controller\Adminhtml\ContentBlock;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Ordo\Automation\Model\ContentBlock\RssFetcher;
use Ordo\Automation\Model\ContentBlockRepository;

/**
 * On-demand "refresh now" for a single RSS content block — synchronous, unlike
 * Cron\RefreshRssContentBlocks which sweeps every enabled rss block on its own 30-min schedule.
 * An admin waiting on this button click wants to know right away whether the feed is reachable,
 * not to wait for the next cron tick.
 *
 * ADMIN_RESOURCE references the ACL resource id string the (separately owned) admin CRUD task
 * registers in etc/acl.xml — not defined here.
 */
class RefreshRss extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Ordo_Automation::content_blocks';

    public function __construct(
        Context $context,
        private readonly ContentBlockRepository $contentBlockRepository,
        private readonly RssFetcher $rssFetcher,
        private readonly JsonFactory $jsonFactory
    ) {
        parent::__construct($context);
    }

    public function execute(): Json
    {
        $result = $this->jsonFactory->create();
        $blockId = (int) $this->getRequest()->getParam('content_block_id');

        $block = $blockId > 0 ? $this->contentBlockRepository->getById($blockId) : null;
        if ($block === null) {
            return $result->setData([
                'success' => false,
                'message' => (string) __('Content block not found.'),
            ]);
        }

        if ($block->getType() !== 'rss') {
            return $result->setData([
                'success' => false,
                'message' => (string) __('This content block is not an RSS feed.'),
            ]);
        }

        try {
            $this->rssFetcher->fetch($block);
        } catch (\Throwable $e) {
            return $result->setData([
                'success' => false,
                'message' => (string) __('Refresh failed: %1', $e->getMessage()),
            ]);
        }

        return $result->setData([
            'success' => true,
            'message' => (string) __('Feed refreshed.'),
        ]);
    }
}
