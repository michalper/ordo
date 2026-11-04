<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\ScoreRule;

use Magento\Framework\App\Request\DataPersistorInterface;
use Magento\Ui\DataProvider\AbstractDataProvider;
use Ordo\Automation\Model\ResourceModel\ScoreRule\CollectionFactory as ScoreRuleCollectionFactory;
use Ordo\Automation\Model\ResourceModel\ScoreRule\Website\CollectionFactory as ScoreRuleWebsiteCollectionFactory;

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
        private readonly ScoreRuleWebsiteCollectionFactory $scoreRuleWebsiteCollectionFactory,
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
            /** @var array<string, mixed> $ruleData */
            $ruleData = $scoreRule->getData();
            $ruleData['website_ids'] = $this->loadWebsiteIds($ruleId);
            $this->loadedData[$ruleId] = $ruleData;
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

    /**
     * @return int[]
     */
    private function loadWebsiteIds(int $scoreRuleId): array
    {
        $collection = $this->scoreRuleWebsiteCollectionFactory->create();
        $collection->addScoreRuleFilter($scoreRuleId);

        $websiteIds = [];
        foreach ($collection as $row) {
            $websiteIds[] = $row->getWebsiteId();
        }

        return $websiteIds;
    }
}
