<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

/**
 * The OUTER (top-level) condition Type dropdown on ordo_segment_form.xml only - adds a synthetic
 * "Group" option on top of everything ConditionType already lists, so an admin can nest a
 * sub-list of conditions with its own independent AND/OR (see ordo_segment_form.xml's
 * group_logic/group_conditions fields and Model\Condition\GroupWalker, the shared tree-walk
 * both SegmentMatcher and Model\Segment\SegmentMemberResolver delegate to).
 *
 * 'group' is deliberately NOT registered in ConditionPool - the matcher/resolver/dispatcher code
 * all special-case `$spec['type'] === 'group'` BEFORE ever calling ConditionPool::get(), so
 * there's no real Condition class backing it, and ConditionPool::getAvailableTypes() (what
 * ConditionType itself lists) never needs to know it exists. This class exists purely so the
 * synthetic option is added in exactly one place (this form's outer Type field) rather than the
 * nested group_conditions dynamicRows' own Type field, which reuses the plain ConditionType
 * source and therefore cannot themselves contain a further nested group - one level of nesting
 * only, matching what SegmentSaveProcessor/DataProvider read and write today.
 */
class ConditionTypeWithGroup implements OptionSourceInterface
{
    public function __construct(
        private readonly ConditionType $conditionType
    ) {
    }

    /**
     * @return array<int, array{value: string, label: string|\Magento\Framework\Phrase}>
     */
    public function toOptionArray(): array
    {
        $options = $this->conditionType->toOptionArray();
        $options[] = ['value' => 'group', 'label' => __('Group (nested AND/OR)')];

        return $options;
    }
}
