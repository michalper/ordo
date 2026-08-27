<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model;

use Ordo\Automation\Model\FreeGiftSelection;
use PHPUnit\Framework\TestCase;

class FreeGiftSelectionTest extends TestCase
{
    public function testGettersAndSetters(): void
    {
        $selection = new FreeGiftSelection();
        $selection->setSkus(['SKU-A', 'SKU-B']);

        self::assertSame(['SKU-A', 'SKU-B'], $selection->getSkus());
    }
}
