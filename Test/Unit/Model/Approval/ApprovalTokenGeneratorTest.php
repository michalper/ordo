<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\Approval;

use Ordo\Automation\Model\Approval\ApprovalTokenGenerator;
use Ordo\Automation\Model\RandomTokenGenerator;
use PHPUnit\Framework\TestCase;

class ApprovalTokenGeneratorTest extends TestCase
{
    public function testGenerateReturns64LowercaseHexCharsMatchingTheTokenColumnWidth(): void
    {
        $token = (new ApprovalTokenGenerator(new RandomTokenGenerator()))->generate();

        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $token);
    }

    public function testGenerateReturnsADifferentTokenEachCall(): void
    {
        $generator = new ApprovalTokenGenerator(new RandomTokenGenerator());

        self::assertNotSame($generator->generate(), $generator->generate());
    }
}
