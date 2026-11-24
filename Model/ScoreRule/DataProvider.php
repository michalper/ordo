<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\ScoreRule;

use Magento\Framework\App\Request\DataPersistorInterface;
use Magento\Ui\DataProvider\AbstractDataProvider;
use Ordo\Automation\Model\ResourceModel\ScoreRule\CollectionFactory as ScoreRuleCollectionFactory;
use Ordo\Automation\Model\ResourceModel\ScoreRule\Website\CollectionFactory as ScoreRuleWebsiteCollectionFactory;
use Ordo\Automation\Model\ScoreRuleWebsite;

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

        $rules = $this->collection->getItems();
        $websiteIdsByRule = $this->loadWebsiteIdsByRule(array_map(
            static fn ($scoreRule): int => (int) $scoreRule->getEntityId(),
            $rules
        ));

        foreach ($rules as $scoreRule) {
            $ruleId = (int) $scoreRule->getEntityId();
            /** @var array<string, mixed> $ruleData */
            $ruleData = $scoreRule->getData();
            $ruleData['website_ids'] = $websiteIdsByRule[$ruleId] ?? [];
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
     * One batch query for every rule on this page, not one query per rule - found during a
     * full-repo /simplify sweep; same reasoning (and the same addScoreRuleIdsFilter() query)
     * already used by ScoreRuleEvaluator::loadWebsiteIdsByRule().
     *
     * @param int[] $scoreRuleIds
     * @return array<int, int[]>
     */
    private function loadWebsiteIdsByRule(array $scoreRuleIds): array
    {
        if ($scoreRuleIds === []) {
            return [];
        }

        $websiteIdsByRule = [];
        $collection = $this->scoreRuleWebsiteCollectionFactory->create();
        $collection->addScoreRuleIdsFilter($scoreRuleIds);
        foreach ($collection as $row) {
            /** @var ScoreRuleWebsite $row */
            $websiteIdsByRule[(int) $row->getScoreRuleId()][] = $row->getWebsiteId();
        }

        return $websiteIdsByRule;
    }
}
