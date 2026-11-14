<?php
declare(strict_types=1);

namespace Ordo\Automation\Ui\Component\Listing\Column;

use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Ui\Component\Listing\Columns\Column;
use Ordo\Automation\Model\ConversationMessage;

/**
 * One plain navigational link, "Reply" - not a Magento_Ui post-with-confirm action like
 * AbstractEntityActionsColumn's edit/delete (there's nothing to confirm, and the actual reply
 * text has to be typed somewhere a confirm dialog cannot provide), so this just links back to
 * this same grid's own page with a "reply_to" query param, which Block\Adminhtml\
 * ConversationMessage\ReplyForm reads to pre-fill the inline reply form below the grid. Shown
 * only for an inbound row - replying to an outbound row (one this admin already sent) makes no
 * sense.
 */
class ConversationMessageActions extends Column
{
    public function __construct(
        ContextInterface $context,
        UiComponentFactory $uiComponentFactory,
        private readonly UrlInterface $urlBuilder,
        array $components = [],
        array $data = []
    ) {
        parent::__construct($context, $uiComponentFactory, $components, $data);
    }

    public function prepareDataSource(array $dataSource): array
    {
        if (!isset($dataSource['data']['items'])) {
            return $dataSource;
        }

        foreach ($dataSource['data']['items'] as &$item) {
            $direction = $item['direction'] ?? ConversationMessage::DIRECTION_INBOUND;
            if ($direction !== ConversationMessage::DIRECTION_INBOUND) {
                continue;
            }

            $item[$this->getData('name')] = [
                'reply' => [
                    'href' => $this->urlBuilder->getUrl(
                        'ordo/conversationmessage/index',
                        ['reply_to' => $item['entity_id']]
                    ),
                    'label' => __('Reply'),
                ],
            ];
        }

        return $dataSource;
    }
}
