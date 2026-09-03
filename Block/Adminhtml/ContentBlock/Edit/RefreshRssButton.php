<?php
declare(strict_types=1);

namespace Ordo\Automation\Block\Adminhtml\ContentBlock\Edit;

use Magento\Backend\Block\Widget\Context;
use Magento\Framework\Registry;
use Magento\Framework\View\Element\UiComponent\Control\ButtonProviderInterface;
use Ordo\Automation\Block\Adminhtml\Shared\Edit\GenericButton;
use Ordo\Automation\Model\ContentBlock;

/**
 * On-demand "refresh now" for a single RSS content block — AJAX-POSTs to
 * Controller\Adminhtml\ContentBlock\RefreshRss for the currently loaded block. Only
 * meaningful for an already-saved rss-type block: a brand new/unsaved block has no entity_id
 * to refresh, and a snippet/product_feed block has no feed to fetch — so this returns [] (no
 * button registered at all) unless both conditions hold, same short-circuit technique
 * DeleteButton uses for "no entity_id yet".
 */
class RefreshRssButton extends GenericButton implements ButtonProviderInterface
{
    public function __construct(
        Context $context,
        private readonly Registry $registry
    ) {
        parent::__construct($context);
    }

    /**
     * @return array<string, mixed>
     */
    public function getButtonData(): array
    {
        $entityId = $this->getEntityId();
        if (!$entityId) {
            return [];
        }

        /** @var ContentBlock|null $contentBlock */
        $contentBlock = $this->registry->registry('ordo_content_block');
        if (!$contentBlock || $contentBlock->getType() !== 'rss') {
            return [];
        }

        return [
            'label' => __('Refresh Feed Now'),
            'class' => 'action-secondary',
            'on_click' => sprintf(
                "jQuery.post('%s', {content_block_id: %d, form_key: window.FORM_KEY}, function(response) {"
                . ' alert(response.message); });',
                $this->getUrl('ordo/contentblock/refreshRss'),
                $entityId
            ),
            'sort_order' => 30,
        ];
    }
}
