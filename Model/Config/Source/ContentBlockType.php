<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

/**
 * The type options for a content block — must match the producer types wired in
 * Model\ContentBlock\ProducerPool (etc/di.xml) and Controller\Adminhtml\ContentBlock\Save's
 * per-type config-building switch.
 */
class ContentBlockType implements OptionSourceInterface
{
    public const SNIPPET = 'snippet';
    public const RSS = 'rss';
    public const PRODUCT_FEED = 'product_feed';

    /**
     * @return array<int, array{value: string, label: \Magento\Framework\Phrase}>
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => self::SNIPPET, 'label' => __('HTML Snippet')],
            ['value' => self::RSS, 'label' => __('RSS Feed')],
            ['value' => self::PRODUCT_FEED, 'label' => __('Product Feed')],
        ];
    }
}
