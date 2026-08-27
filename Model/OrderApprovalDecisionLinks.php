<?php
declare(strict_types=1);

namespace Ordo\Automation\Model;

use Magento\Framework\DataObject;
use Ordo\Automation\Api\Data\OrderApprovalDecisionLinksInterface;

class OrderApprovalDecisionLinks extends DataObject implements OrderApprovalDecisionLinksInterface
{
    public function getApproveUrl(): string
    {
        return (string) $this->getData('approve_url');
    }

    public function setApproveUrl(string $approveUrl): self
    {
        $this->setData('approve_url', $approveUrl);
        return $this;
    }

    public function getRejectUrl(): string
    {
        return (string) $this->getData('reject_url');
    }

    public function setRejectUrl(string $rejectUrl): self
    {
        $this->setData('reject_url', $rejectUrl);
        return $this;
    }
}
