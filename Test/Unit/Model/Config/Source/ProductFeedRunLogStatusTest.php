<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\Config\Source;

use Ordo\Automation\Model\Config\Source\ProductFeedRunLogStatus;
use PHPUnit\Framework\TestCase;

class ProductFeedRunLogStatusTest extends TestCase
{
    public function testToOptionArrayReturnsSuccessAndError(): void
    {
        $values = array_column((new ProductFeedRunLogStatus())->toOptionArray(), 'value');

        self::assertSame(['success', 'error'], $values);
    }
}
