<?php
declare(strict_types=1);

namespace Ordo\Automation\Block\Adminhtml\Shared\Edit;

use Magento\Framework\View\Element\UiComponent\Control\ButtonProviderInterface;

/**
 * Was eight byte-identical copies (one per admin edit form) — nothing about "go back to the
 * grid" differs per entity, so the ui_component form XMLs reference this class directly.
 */
class BackButton extends GenericButton implements ButtonProviderInterface
{
    /**
     * @return array<string, mixed>
     */
    public function getButtonData(): array
    {
        return [
            'label' => __('Back'),
            'on_click' => sprintf("location.href = '%s';", $this->getUrl('*/*/')),
            'class' => 'back',
            'sort_order' => 10,
        ];
    }
}
