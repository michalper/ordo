<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Block\Adminhtml\Campaign\Edit;

use Magento\Backend\Block\Widget\Context;
use Ordo\Automation\Block\Adminhtml\Campaign\Edit\SaveButton;
use PHPUnit\Framework\TestCase;

class SaveButtonTest extends TestCase
{
    public function testGetButtonDataReturnsSaveConfig(): void
    {
        $context = $this->createMock(Context::class);

        $data = (new SaveButton($context))->getButtonData();

        self::assertSame('Save Campaign', (string) $data['label']);
        self::assertSame(90, $data['sort_order']);
        self::assertSame(
            [false],
            $data['data_attribute']['mage-init']['buttonAdapter']['actions'][0]['params']
        );
    }
}
