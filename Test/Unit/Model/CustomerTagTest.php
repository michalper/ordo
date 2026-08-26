<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model;

use Ordo\Automation\Model\CustomerTag;

class CustomerTagTest extends AbstractModelTestCase
{
    public function testConstructsWithoutError(): void
    {
        $model = new CustomerTag($this->makeModelContext(), $this->makeRegistry(), $this->makeModelResource());

        self::assertInstanceOf(CustomerTag::class, $model);
    }
}
