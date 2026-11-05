<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\EmailTemplateDraft;

use Magento\Framework\App\Request\DataPersistorInterface;
use Magento\Ui\DataProvider\AbstractDataProvider;
use Ordo\Automation\Model\ResourceModel\EmailTemplateDraft\CollectionFactory as EmailTemplateDraftCollectionFactory;

/**
 * Feeds the email template draft edit form. Flat entity, no child rows — same shape as
 * WhatsAppTemplate\DataProvider.
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
        EmailTemplateDraftCollectionFactory $collectionFactory,
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

        foreach ($this->collection->getItems() as $draft) {
            $id = (int) $draft->getEntityId();
            $this->loadedData[$id] = $draft->getData();
        }

        /** @var array<string, mixed>|null $persisted */
        $persisted = $this->dataPersistor->get('ordo_email_template_draft');
        if ($persisted) {
            $id = (int) ($persisted['entity_id'] ?? 0);
            if ($id) {
                $this->loadedData[$id] = $persisted;
            }
            $this->dataPersistor->clear('ordo_email_template_draft');
        }

        return $this->loadedData;
    }
}
