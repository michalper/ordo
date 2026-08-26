<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Block\Adminhtml\Campaign\Edit;

use Magento\Backend\Block\Widget\Context;
use Ordo\Automation\Block\Adminhtml\Campaign\Edit\SaveAndContinueButton;
use PHPUnit\Framework\TestCase;

class SaveAndContinueButtonTest extends TestCase
{
    public function testGetButtonDataReturnsSaveAndContinueConfig(): void
    {
        $context = $this->createMock(Context::class);

        $data = (new SaveAndContinueButton($context))->getButtonData();

        self::assertSame('Save & Continue Edit', (string) $data['label']);
        self::assertSame(80, $data['sort_order']);
        self::assertSame(
            [true, ['back' => 'edit']],
            $data['data_attribute']['mage-init']['buttonAdapter']['actions'][0]['params']
        );
    }
}
