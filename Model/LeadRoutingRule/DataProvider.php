<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\LeadRoutingRule;

use Magento\Framework\App\Request\DataPersistorInterface;
use Magento\Ui\DataProvider\AbstractDataProvider;
use Ordo\Automation\Model\ResourceModel\LeadRoutingRule\CollectionFactory as LeadRoutingRuleCollectionFactory;

/**
 * Feeds the lead routing rule edit form - same shape as ScoreRule\DataProvider, plus decoding
 * the "reps" JSON column into the nested {"reps": [...]} array the form's "reps" dynamicRows
 * field expects on load - same dataScope-then-component-name nesting
 * Model\Campaign\DataProvider's own $campaignData['triggers'] = ['triggers' => ...] line already
 * uses for its own identically-named dynamicRows field (Controller\Adminhtml\LeadRoutingRule\Save
 * does the inverse decode on the way back in).
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
        LeadRoutingRuleCollectionFactory $collectionFactory,
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

        foreach ($this->collection->getItems() as $leadRoutingRule) {
            $ruleId = (int) $leadRoutingRule->getEntityId();
            $data = $leadRoutingRule->getData();
            $data['reps'] = ['reps' => $this->decodeReps((string) ($data['reps'] ?? ''))];
            $this->loadedData[$ruleId] = $data;
        }

        /** @var array<string, mixed>|null $persisted */
        $persisted = $this->dataPersistor->get('ordo_lead_routing_rule');
        if ($persisted) {
            $ruleId = (int) ($persisted['entity_id'] ?? 0);
            if ($ruleId) {
                $this->loadedData[$ruleId] = $persisted;
            }
            $this->dataPersistor->clear('ordo_lead_routing_rule');
        }

        return $this->loadedData;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function decodeReps(string $reps): array
    {
        $decoded = json_decode($reps, true);

        return is_array($decoded) ? $decoded : [];
    }
}
