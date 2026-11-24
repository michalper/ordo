<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\AiAgent;

use Ordo\Automation\Model\AiAgent\ApiKeyGenerator;
use Ordo\Automation\Model\RandomTokenGenerator;
use PHPUnit\Framework\TestCase;

class ApiKeyGeneratorTest extends TestCase
{
    public function testGenerateReturnsAPrefixedPlaintextAndItsSha256Hash(): void
    {
        $generator = new ApiKeyGenerator(new RandomTokenGenerator());

        $generated = $generator->generate();

        self::assertStringStartsWith('oaa_', $generated['plaintext']);
        self::assertSame(hash('sha256', $generated['plaintext']), $generated['hash']);
    }

    public function testGenerateReturnsADifferentKeyEachCall(): void
    {
        $generator = new ApiKeyGenerator(new RandomTokenGenerator());

        self::assertNotSame($generator->generate()['plaintext'], $generator->generate()['plaintext']);
    }

    public function testHashIsDeterministicForTheSamePlaintext(): void
    {
        $generator = new ApiKeyGenerator(new RandomTokenGenerator());

        self::assertSame($generator->hash('some-key'), $generator->hash('some-key'));
        self::assertSame(hash('sha256', 'some-key'), $generator->hash('some-key'));
    }
}
