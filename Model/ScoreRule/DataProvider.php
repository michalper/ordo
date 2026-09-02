<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\ScoreRule;

use Magento\Framework\App\Request\DataPersistorInterface;
use Magento\Ui\DataProvider\AbstractDataProvider;
use Ordo\Automation\Model\ResourceModel\ScoreRule\CollectionFactory as ScoreRuleCollectionFactory;

/**
 * Feeds the score rule edit form. Flat entity, no child rows — simpler than
 * Segment\DataProvider (which also loads condition rows).
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
        ScoreRuleCollectionFactory $collectionFactory,
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

        foreach ($this->collection->getItems() as $scoreRule) {
            $ruleId = (int) $scoreRule->getEntityId();
            $this->loadedData[$ruleId] = $scoreRule->getData();
        }

        /** @var array<string, mixed>|null $persisted */
        $persisted = $this->dataPersistor->get('ordo_score_rule');
        if ($persisted) {
            $ruleId = (int) ($persisted['entity_id'] ?? 0);
            if ($ruleId) {
                $this->loadedData[$ruleId] = $persisted;
            }
            $this->dataPersistor->clear('ordo_score_rule');
        }

        return $this->loadedData;
    }
}
