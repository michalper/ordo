<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\Approval;

use Ordo\Automation\Model\Approval\ApprovalTokenGenerator;
use PHPUnit\Framework\TestCase;

class ApprovalTokenGeneratorTest extends TestCase
{
    public function testGenerateReturns64LowercaseHexCharsMatchingTheTokenColumnWidth(): void
    {
        $token = (new ApprovalTokenGenerator())->generate();

        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $token);
    }

    public function testGenerateReturnsADifferentTokenEachCall(): void
    {
        $generator = new ApprovalTokenGenerator();

        self::assertNotSame($generator->generate(), $generator->generate());
    }
}
