<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\ResourceModel\ScoreRule;

use Ordo\Automation\Model\ResourceModel\ScoreRule\Website;
use Ordo\Automation\Test\Unit\Model\ResourceModel\AbstractDbTestCase;

class WebsiteTest extends AbstractDbTestCase
{
    public function testInitializesWithScoreRuleWebsiteTableAndEntityIdField(): void
    {
        $resource = new Website($this->makeDbContext());

        self::assertSame('ordo_score_rule_website', $resource->getMainTable());
        self::assertSame('entity_id', $resource->getIdFieldName());
    }
}
