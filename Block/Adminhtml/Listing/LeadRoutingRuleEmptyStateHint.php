<?php
declare(strict_types=1);

namespace Ordo\Automation\Block\Adminhtml\Listing;

use Magento\Framework\View\Element\Template\Context;
use Ordo\Automation\Model\ResourceModel\LeadRoutingRule\CollectionFactory;

class LeadRoutingRuleEmptyStateHint extends AbstractEmptyStateHint
{
    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        Context $context,
        private readonly CollectionFactory $collectionFactory,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    protected function getCollectionSize(): int
    {
        return $this->collectionFactory->create()->getSize();
    }
}
