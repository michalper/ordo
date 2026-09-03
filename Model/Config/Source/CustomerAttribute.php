<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\Config\Source;

use Magento\Customer\Model\ResourceModel\Attribute\CollectionFactory as CustomerAttributeCollectionFactory;
use Magento\Framework\Data\OptionSourceInterface;

/**
 * Every customer-entity attribute a score rule's attribute_code can be pointed at —
 * ScoreRuleEvaluator::getAttributeValue() resolves a code either via one of 4 hardcoded core
 * getters (group_id/website_id/email/store_id) or, for everything else, via
 * CustomerInterface::getCustomAttribute(), so the option list needs to cover both: real EAV
 * custom attributes AND the core columns, since Magento's customer entity is itself fully
 * EAV-backed (group_id/website_id/email are all real eav_attribute rows for entity_type
 * "customer", not a separate namespace).
 *
 * addVisibleFilter() (is_visible=1) keeps the list to attributes an admin would recognize and
 * that can actually resolve via getCustomAttribute() — but store_id is itself is_visible=0 in a
 * stock Magento install (confirmed against a real database, not assumed) despite being one of
 * ScoreRuleEvaluator's 4 special-cased codes, so it's added back in explicitly below rather than
 * silently dropped from the picker.
 */
class CustomerAttribute implements OptionSourceInterface
{
    /**
     * Core evaluator-special-cased codes that addVisibleFilter() would otherwise hide.
     */
    private const FORCE_INCLUDE_CODES = ['store_id'];

    public function __construct(
        private readonly CustomerAttributeCollectionFactory $collectionFactory
    ) {
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public function toOptionArray(): array
    {
        $collection = $this->collectionFactory->create();
        $collection->addVisibleFilter();

        $codes = [];
        $options = [];
        foreach ($collection as $attribute) {
            $code = (string) $attribute->getAttributeCode();
            $codes[$code] = true;
            $options[] = [
                'value' => $code,
                'label' => (string) ($attribute->getFrontendLabel() ?: $code),
            ];
        }

        foreach (self::FORCE_INCLUDE_CODES as $code) {
            if (!isset($codes[$code])) {
                $options[] = ['value' => $code, 'label' => $code];
            }
        }

        usort($options, static fn (array $a, array $b): int => $a['label'] <=> $b['label']);

        return $options;
    }
}
