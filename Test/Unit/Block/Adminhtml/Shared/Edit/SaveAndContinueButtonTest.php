<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Block\Adminhtml\Shared\Edit;

use Magento\Backend\Block\Widget\Context;
use Ordo\Automation\Block\Adminhtml\Shared\Edit\SaveAndContinueButton;
use PHPUnit\Framework\TestCase;

class SaveAndContinueButtonTest extends TestCase
{
    public function testGetButtonDataReturnsSaveAndContinueConfig(): void
    {
        $context = $this->createStub(Context::class);

        $data = (new SaveAndContinueButton($context, 'ordo_segment_form.ordo_segment_form'))
            ->getButtonData();

        self::assertSame('Save & Continue Edit', (string) $data['label']);
        self::assertSame(80, $data['sort_order']);

        $action = $data['data_attribute']['mage-init']['buttonAdapter']['actions'][0];
        self::assertSame('ordo_segment_form.ordo_segment_form', $action['targetName']);
        self::assertSame('save', $action['actionName']);
        self::assertSame([true, ['back' => 'edit']], $action['params']);
    }
}
