<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\ContentBlock;

use Magento\Framework\App\Request\DataPersistorInterface;
use Magento\Ui\DataProvider\AbstractDataProvider;
use Ordo\Automation\Model\ResourceModel\ContentBlock\CollectionFactory as ContentBlockCollectionFactory;

/**
 * Feeds the content block edit form. Flat entity like Model\ScoreRule\DataProvider, with one
 * extra step: the type-specific fields (html / feed_url / item_count / source / category_id /
 * rule_id) don't live as their own columns — they're packed into the "config" JSON column
 * (see ContentBlock::getConfigArray()). Decode it and merge the flat keys into the row so the
 * edit form's type-specific fields (see ordo_contentblock_form.xml's switcherConfig) populate.
 */
class DataProvider extends AbstractDataProvider
{
    protected ?array $loadedData = null;

    /**
     * @param array<string, mixed> $meta
     * @param array<string, mixed> $data
     */
    public function __construct(
        $name,
        $primaryFieldName,
        $requestFieldName,
        ContentBlockCollectionFactory $collectionFactory,
        private readonly DataPersistorInterface $dataPersistor,
        array $meta = [],
        array $data = []
    ) {
        $this->collection = $collectionFactory->create();
        parent::__construct($name, $primaryFieldName, $requestFieldName, $meta, $data);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getData(): array
    {
        if ($this->loadedData !== null) {
            return $this->loadedData;
        }

        $this->loadedData = [];

        foreach ($this->collection->getItems() as $contentBlock) {
            /** @var \Ordo\Automation\Model\ContentBlock $contentBlock */
            $blockId = (int) $contentBlock->getEntityId();
            // phpcs:ignore Magento2.Performance.ForeachArrayMerge.ForeachArrayMerge
            $this->loadedData[$blockId] = array_merge($contentBlock->getData(), $contentBlock->getConfigArray());
        }

        /** @var array<string, mixed>|null $persisted */
        $persisted = $this->dataPersistor->get('ordo_content_block');
        if ($persisted) {
            $blockId = (int) ($persisted['entity_id'] ?? 0);
            if ($blockId) {
                $this->loadedData[$blockId] = $persisted;
            }
            $this->dataPersistor->clear('ordo_content_block');
        }

        return $this->loadedData;
    }
}
