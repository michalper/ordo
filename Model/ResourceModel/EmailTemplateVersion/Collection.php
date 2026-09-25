<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\ResourceModel\EmailTemplateVersion;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;
use Ordo\Automation\Model\EmailTemplateVersion as EmailTemplateVersionModel;
use Ordo\Automation\Model\ResourceModel\EmailTemplateVersion as EmailTemplateVersionResource;

class Collection extends AbstractCollection
{
    protected function _construct(): void
    {
        $this->_init(EmailTemplateVersionModel::class, EmailTemplateVersionResource::class);
    }

    public function addTemplateFilter(int $templateId): self
    {
        $this->addFieldToFilter('template_id', ['eq' => $templateId]);
        return $this;
    }
}
