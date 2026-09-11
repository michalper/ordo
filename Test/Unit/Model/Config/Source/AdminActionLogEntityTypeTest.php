<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\Config\Source;

use Ordo\Automation\Model\Config\Source\AdminActionLogEntityType;
use PHPUnit\Framework\TestCase;

class AdminActionLogEntityTypeTest extends TestCase
{
    public function testToOptionArrayReturnsEveryAuditedEntityType(): void
    {
        $values = array_column(new AdminActionLogEntityType()->toOptionArray(), 'value');

        self::assertSame(
            ['campaign', 'segment', 'content_block', 'free_gift_offer', 'score_rule', 'ad_audience', 'whatsapp_template'],
            $values
        );
    }
}
