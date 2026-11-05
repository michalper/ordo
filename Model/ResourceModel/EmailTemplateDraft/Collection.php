<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\ResourceModel\EmailTemplateDraft;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;
use Ordo\Automation\Model\EmailTemplateDraft as EmailTemplateDraftModel;
use Ordo\Automation\Model\ResourceModel\EmailTemplateDraft as EmailTemplateDraftResource;

class Collection extends AbstractCollection
{
    protected function _construct(): void
    {
        $this->_init(EmailTemplateDraftModel::class, EmailTemplateDraftResource::class);
    }

    public function addTemplateFilter(int $templateId): self
    {
        $this->addFieldToFilter('template_id', ['eq' => $templateId]);
        return $this;
    }
}
