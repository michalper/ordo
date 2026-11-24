<?php
declare(strict_types=1);

namespace Ordo\Automation\Block\Adminhtml\Rfm\Grid\Column;

use Magento\Framework\App\CacheInterface;
use Magento\Framework\Serialize\SerializerInterface;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Ui\Component\Listing\Columns\Column;
use Ordo\Automation\Model\ResourceModel\Segment\CollectionFactory as SegmentCollectionFactory;
use Ordo\Automation\Model\Segment\SegmentMemberResolver;

/**
 * Answers the one thing the RFM report didn't (ROADMAP.md "Admin UX" Phase 2): given this
 * customer's current RFM standing, which of this merchant's own saved segments would they
 * qualify for right now — turning a report that ended at a number into one that leads directly
 * into an action ("go run/adjust the campaign tied to that segment"), instead of requiring a
 * merchant to cross-reference this grid against the Segments screen by hand.
 *
 * Evaluated segment-by-segment rather than customer-by-customer:
 * SegmentMemberResolver::getMatchingCustomerIds() computes one segment's whole matching set in
 * a handful of queries (sharing one RFM aggregate/percentile snapshot across the page load via
 * its beginBatch()/endBatch()), so resolving "N enabled segments x page of customers" costs N
 * resolves total, not N x page-size point checks against SegmentMatcher::isCustomerInSegment().
 */
class MatchingSegments extends Column
{
    private const string CACHE_KEY = 'ordo_rfm_matching_segments';

    /**
     * Found during a full-repo /simplify sweep: buildSegmentNamesByCustomerId() re-resolved
     * every enabled segment against the entire customer base on every grid page/sort/filter
     * action, each one a fresh set of resolver queries, to produce the same ~20-50-row page's
     * worth of names every time. Same short-TTL cache DashboardViewModel::cachedCount() already
     * uses for its own page-load counts - there's no cache-invalidation hook tied to
     * segment/order changes here, so a short TTL (not an indefinite one) is what keeps a stale
     * result bounded without needing one.
     */
    private const int CACHE_LIFETIME = 60;

    /**
     * @param array<int, mixed> $components
     * @param array<string, mixed> $data
     */
    public function __construct(
        ContextInterface $context,
        UiComponentFactory $uiComponentFactory,
        private readonly SegmentCollectionFactory $segmentCollectionFactory,
        private readonly SegmentMemberResolver $segmentMemberResolver,
        private readonly CacheInterface $cache,
        private readonly SerializerInterface $serializer,
        array $components = [],
        array $data = []
    ) {
        parent::__construct($context, $uiComponentFactory, $components, $data);
    }

    /**
     * @param array<string, mixed> $dataSource
     * @return array<string, mixed>
     */
    public function prepareDataSource(array $dataSource): array
    {
        /** @var array<string, mixed> $dataSource parent's own signature has no generics */
        $dataSource = parent::prepareDataSource($dataSource);

        $sourceData = $dataSource['data'] ?? null;
        $items = is_array($sourceData) ? ($sourceData['items'] ?? null) : null;

        if (!is_array($sourceData) || !is_array($items) || $items === []) {
            return $dataSource;
        }

        $nameData = $this->getData('name');
        $fieldName = is_string($nameData) ? $nameData : 'matching_segments';
        $segmentNamesByCustomerId = $this->buildSegmentNamesByCustomerId();

        foreach ($items as &$item) {
            if (!is_array($item)) {
                continue;
            }

            $rawCustomerId = $item['entity_id'] ?? null;
            $customerId = is_numeric($rawCustomerId) ? (int) $rawCustomerId : 0;
            $matchingNames = $segmentNamesByCustomerId[$customerId] ?? [];
            $item[$fieldName] = implode(', ', $matchingNames);
        }
        unset($item);

        $sourceData['items'] = $items;
        $dataSource['data'] = $sourceData;

        return $dataSource;
    }

    /**
     * @return array<int, string[]>
     */
    private function buildSegmentNamesByCustomerId(): array
    {
        $cached = $this->cache->load(self::CACHE_KEY);
        if ($cached !== false) {
            $decoded = $this->serializer->unserialize($cached);
            return is_array($decoded) ? $decoded : [];
        }

        $result = $this->resolveSegmentNamesByCustomerId();

        $this->cache->save($this->serializer->serialize($result), self::CACHE_KEY, [], self::CACHE_LIFETIME);

        return $result;
    }

    /**
     * @return array<int, string[]>
     */
    private function resolveSegmentNamesByCustomerId(): array
    {
        $segments = $this->segmentCollectionFactory->create();
        $segments->addFieldToFilter('enabled', '1');

        $result = [];

        // One RFM snapshot for the whole page load — per top-level resolve the resolver would
        // otherwise re-run its whole-base aggregate/percentile scans once per enabled segment.
        $this->segmentMemberResolver->beginBatch();

        try {
            /** @var \Ordo\Automation\Model\Segment $segment */
            foreach ($segments as $segment) {
                $segmentId = $segment->getEntityId();

                if ($segmentId === null) {
                    continue;
                }

                foreach ($this->segmentMemberResolver->getMatchingCustomerIds($segmentId) as $customerId) {
                    $result[$customerId][] = $segment->getName();
                }
            }
        } finally {
            $this->segmentMemberResolver->endBatch();
        }

        return $result;
    }
}
