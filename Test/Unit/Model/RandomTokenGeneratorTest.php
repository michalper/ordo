<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model;

use Ordo\Automation\Model\RandomTokenGenerator;
use PHPUnit\Framework\TestCase;

class RandomTokenGeneratorTest extends TestCase
{
    public function testGenerateDefaultsToA32ByteValueAs64HexChars(): void
    {
        $token = (new RandomTokenGenerator())->generate();

        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $token);
    }

    public function testGenerateReturnsADifferentTokenEachCall(): void
    {
        $generator = new RandomTokenGenerator();

        self::assertNotSame($generator->generate(), $generator->generate());
    }

    public function testGenerateRespectsAnExplicitByteCount(): void
    {
        $token = (new RandomTokenGenerator())->generate(16);

        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $token);
    }
}
