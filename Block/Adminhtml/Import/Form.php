<?php
declare(strict_types=1);

namespace Ordo\Automation\Block\Adminhtml\Import;

use Magento\Backend\Block\Template;

/**
 * Shared upload-a-JSON-file form for both Controller\Adminhtml\Campaign\ImportForm and
 * Controller\Adminhtml\Segment\ImportForm - the form itself (a single file input + submit) is
 * identical for both entities, only the submit URL/back-to-grid URL/label text differ, so those
 * three are supplied per-layout via block <arguments> (see
 * view/adminhtml/layout/ordo_campaign_importform_index.xml/ordo_segment_importform_index.xml)
 * instead of two near-duplicate blocks/templates.
 */
class Form extends Template
{
    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        Template\Context $context,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    public function getImportUrl(): string
    {
        return $this->getUrl($this->stringData('import_url_path'));
    }

    public function getBackUrl(): string
    {
        return $this->getUrl($this->stringData('back_url_path'));
    }

    public function getEntityLabel(): string
    {
        return $this->stringData('entity_label');
    }

    private function stringData(string $key): string
    {
        $value = $this->getData($key);
        return is_string($value) ? $value : '';
    }
}
