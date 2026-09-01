<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\Campaign;

use Magento\Framework\App\Request\DataPersistorInterface;
use Magento\Ui\DataProvider\AbstractDataProvider;
use Ordo\Automation\Model\ResourceModel\Campaign\Action\CollectionFactory as CampaignActionCollectionFactory;
use Ordo\Automation\Model\ResourceModel\Campaign\Condition\CollectionFactory as CampaignConditionCollectionFactory;
use Ordo\Automation\Model\ResourceModel\Campaign\CollectionFactory as CampaignCollectionFactory;
use Ordo\Automation\Model\ResourceModel\Campaign\Trigger\CollectionFactory as CampaignTriggerCollectionFactory;

/**
 * Feeds the campaign edit form, including the three dynamicRows sections (triggers,
 * conditions, actions) — those aren't columns on ordo_campaign itself, so they're loaded
 * separately here and nested into each campaign's data array under the same keys the form's
 * dynamicRows fields expect (see ordo_campaign_form.xml).
 */
class DataProvider extends AbstractDataProvider
{
    protected ?array $loadedData = null;

    public function __construct(
        $name,
        $primaryFieldName,
        $requestFieldName,
        CampaignCollectionFactory $collectionFactory,
        private readonly CampaignTriggerCollectionFactory $campaignTriggerCollectionFactory,
        private readonly CampaignConditionCollectionFactory $campaignConditionCollectionFactory,
        private readonly CampaignActionCollectionFactory $campaignActionCollectionFactory,
        private readonly DataPersistorInterface $dataPersistor,
        array $meta = [],
        array $data = []
    ) {
        $this->collection = $collectionFactory->create();
        parent::__construct($name, $primaryFieldName, $requestFieldName, $meta, $data);
    }

    public function getData(): array
    {
        if ($this->loadedData !== null) {
            return $this->loadedData;
        }

        $this->loadedData = [];

        foreach ($this->collection->getItems() as $campaign) {
            $campaignData = $campaign->getData();
            $campaignId = (int) $campaign->getEntityId();

            $campaignData['triggers'] = $this->loadTriggerRows($campaignId);
            $campaignData['conditions'] = $this->loadChildRows($this->campaignConditionCollectionFactory->create(), $campaignId);
            $campaignData['actions'] = $this->loadActionRows($campaignId);

            $this->loadedData[$campaignId] = $campaignData;
        }

        $persisted = $this->dataPersistor->get('ordo_campaign');
        if ($persisted) {
            $campaignId = $persisted['entity_id'] ?? null;
            if ($campaignId) {
                $this->loadedData[$campaignId] = $persisted;
            }
            $this->dataPersistor->clear('ordo_campaign');
        }

        return $this->loadedData;
    }

    /**
     * Spreads the saved params back into the row's dedicated fields (tag, amount, ...) too —
     * not just params_json — so the switcherConfig fields in ordo_campaign_form.xml
     * pre-populate correctly when editing an existing campaign, instead of only showing the
     * raw JSON.
     *
     * @return array<int, array<string, mixed>>
     */
    private function loadChildRows($collection, int $campaignId): array
    {
        $collection->addCampaignFilter($campaignId);

        $rows = [];
        foreach ($collection as $row) {
            $paramsJson = (string) $row->getData('params');
            $decoded = json_decode($paramsJson, true);

            $rowData = [
                'type' => $row->getData('type'),
                'params_json' => $paramsJson,
            ];

            if (is_array($decoded)) {
                $rowData += $decoded;
            }

            $rows[] = $rowData;
        }

        return $rows;
    }

    /**
     * Same shape as loadChildRows(), plus delay_minutes — the one field CampaignActionInterface
     * has that CampaignConditionInterface doesn't, so this can't just be a third loadChildRows()
     * caller.
     *
     * @return array<int, array<string, mixed>>
     */
    private function loadActionRows(int $campaignId): array
    {
        $collection = $this->campaignActionCollectionFactory->create();
        $collection->addCampaignFilter($campaignId);

        $rows = [];
        foreach ($collection as $row) {
            $paramsJson = (string) $row->getData('params');
            $decoded = json_decode($paramsJson, true);

            $rowData = [
                'type' => $row->getData('type'),
                'params_json' => $paramsJson,
                'delay_minutes' => (int) $row->getData('delay_minutes'),
            ];

            if (is_array($decoded)) {
                $rowData += $decoded;
            }

            $rows[] = $rowData;
        }

        return $rows;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function loadTriggerRows(int $campaignId): array
    {
        $collection = $this->campaignTriggerCollectionFactory->create();
        $collection->addCampaignFilter($campaignId);

        $rows = [];
        foreach ($collection as $row) {
            $rows[] = ['trigger_event' => $row->getData('trigger_event')];
        }

        return $rows;
    }
}
