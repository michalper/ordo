<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

/**
 * Options for HasTag/VisitorHasTag's match_mode field - whether the condition is satisfied by
 * any one of the selected tags, or requires every one of them.
 */
class TagMatchMode implements OptionSourceInterface
{
    public const string ANY = 'any';
    public const string ALL = 'all';

    /**
     * @return array<int, array{value: string, label: \Magento\Framework\Phrase}>
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => self::ANY, 'label' => __('Has ANY of these tags')],
            ['value' => self::ALL, 'label' => __('Has ALL of these tags')],
        ];
    }
}
