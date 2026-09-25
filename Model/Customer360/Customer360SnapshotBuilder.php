<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\Customer360;

use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Framework\App\ResourceConnection;
use Ordo\Automation\Api\CustomerTagManagementInterface;
use Ordo\Automation\Model\CustomerScoreManager;
use Ordo\Automation\Model\LoyaltyTierCalculator;
use Ordo\Automation\Model\ResourceModel\Segment\CollectionFactory as SegmentCollectionFactory;
use Ordo\Automation\Model\ResourceModel\SurveyPrompt\CollectionFactory as SurveyPromptCollectionFactory;
use Ordo\Automation\Model\Rfm\RfmCalculator;
use Ordo\Automation\Model\Segment\SegmentMatcher;
use Ordo\Automation\Model\SurveyPrompt;

/**
 * Gathers one customer's Customer360Snapshot from every already-existing per-customer data
 * source in this module. Segment matching is done one enabled segment at a time via
 * SegmentMatcher::isCustomerInSegment() (not SegmentMemberResolver's whole-segment resolve) -
 * the right tradeoff for a single-customer page view, the opposite of
 * Block\Adminhtml\Rfm\Grid\Column\MatchingSegments's own per-grid-page reasoning.
 */
class Customer360SnapshotBuilder
{
    public function __construct(
        private readonly CustomerScoreManager $customerScoreManager,
        private readonly LoyaltyTierCalculator $loyaltyTierCalculator,
        private readonly RfmCalculator $rfmCalculator,
        private readonly CustomerTagManagementInterface $customerTagManager,
        private readonly SurveyPromptCollectionFactory $surveyPromptCollectionFactory,
        private readonly SegmentCollectionFactory $segmentCollectionFactory,
        private readonly SegmentMatcher $segmentMatcher,
        private readonly ResourceConnection $resourceConnection
    ) {
    }

    public function build(CustomerInterface $customer): Customer360Snapshot
    {
        $customerId = (int) $customer->getId();
        $score = $this->customerScoreManager->getScore($customerId);
        [$orderCount, $orderTotal] = $this->getOrderStats($customerId);

        return new Customer360Snapshot(
            customerId: $customerId,
            email: (string) $customer->getEmail(),
            name: trim($customer->getFirstname() . ' ' . $customer->getLastname()),
            leadScore: $score,
            loyaltyTier: $this->loyaltyTierCalculator->getTierLabel(
                $this->loyaltyTierCalculator->getTierForScore($score)
            ),
            recencyDays: $this->rfmCalculator->getRecencyDays($customerId),
            orderFrequency: $this->rfmCalculator->getFrequency($customerId),
            monetaryTotal: $this->rfmCalculator->getMonetaryTotal($customerId),
            rfmScoreLabel: $this->rfmCalculator->getRfmScoreLabel($customerId),
            tags: $this->customerTagManager->getTags($customerId),
            npsScore: $this->getLatestNpsScore($customerId),
            matchingSegmentNames: $this->getMatchingSegmentNames($customerId),
            orderCount: $orderCount,
            orderTotal: $orderTotal
        );
    }

    private function getLatestNpsScore(int $customerId): ?int
    {
        $collection = $this->surveyPromptCollectionFactory->create();
        $collection->addLatestResponseFilter($customerId);

        /** @var SurveyPrompt $latest */
        $latest = $collection->getFirstItem();

        return $latest->getScore();
    }

    /**
     * @return string[]
     */
    private function getMatchingSegmentNames(int $customerId): array
    {
        $segments = $this->segmentCollectionFactory->create();
        $segments->addFieldToFilter('enabled', '1');

        $names = [];
        foreach ($segments as $segment) {
            if ($this->segmentMatcher->isCustomerInSegment((int) $segment->getId(), $customerId)) {
                $names[] = $segment->getName();
            }
        }

        return $names;
    }

    /**
     * @return array{0: int, 1: float}
     */
    private function getOrderStats(int $customerId): array
    {
        $connection = $this->resourceConnection->getConnection();
        $orderTable = $this->resourceConnection->getTableName('sales_order');

        $select = $connection->select()
            ->from($orderTable, ['order_count' => 'COUNT(*)', 'order_total' => 'COALESCE(SUM(grand_total), 0)'])
            ->where('customer_id = ?', $customerId);

        $row = $connection->fetchRow($select);
        if (!$row) {
            return [0, 0.0];
        }

        return [(int) $row['order_count'], (float) $row['order_total']];
    }
}
