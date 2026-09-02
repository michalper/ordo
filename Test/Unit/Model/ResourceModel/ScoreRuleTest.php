<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\ResourceModel;

use Ordo\Automation\Model\ResourceModel\ScoreRule;

class ScoreRuleTest extends AbstractDbTestCase
{
    public function testInitializesWithScoreRuleTableAndEntityIdField(): void
    {
        $resource = new ScoreRule($this->makeDbContext());

        self::assertSame('ordo_score_rule', $resource->getMainTable());
        self::assertSame('entity_id', $resource->getIdFieldName());
    }
}
