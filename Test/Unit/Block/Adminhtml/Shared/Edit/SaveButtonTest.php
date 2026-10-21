<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Block\Adminhtml\Shared\Edit;

use Magento\Backend\Block\Widget\Context;
use Ordo\Automation\Block\Adminhtml\Shared\Edit\SaveButton;
use PHPUnit\Framework\TestCase;

class SaveButtonTest extends TestCase
{
    public function testGetButtonDataReturnsSaveConfig(): void
    {
        $context = $this->createStub(Context::class);

        $data = (new SaveButton($context, 'Save Campaign', 'ordo_campaign_form.ordo_campaign_form'))
            ->getButtonData();

        self::assertSame('Save Campaign', (string) $data['label']);
        self::assertSame('save primary', $data['class']);
        self::assertSame(90, $data['sort_order']);

        $action = $data['data_attribute']['mage-init']['buttonAdapter']['actions'][0];
        self::assertSame('ordo_campaign_form.ordo_campaign_form', $action['targetName']);
        self::assertSame('save', $action['actionName']);
        self::assertSame([false], $action['params']);
    }
}
