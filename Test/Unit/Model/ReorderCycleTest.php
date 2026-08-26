<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model;

use Ordo\Automation\Model\ReorderCycle;

class ReorderCycleTest extends AbstractModelTestCase
{
    public function testConstructsWithoutError(): void
    {
        $model = new ReorderCycle($this->makeModelContext(), $this->makeRegistry(), $this->makeModelResource());

        self::assertInstanceOf(ReorderCycle::class, $model);
    }
}
