<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model;

use Magento\Framework\Math\Random;
use Ordo\Automation\Model\ReferralCodeGenerator;
use PHPUnit\Framework\TestCase;

class ReferralCodeGeneratorTest extends TestCase
{
    public function testGenerateUniqueReturnsFirstNonCollidingCandidate(): void
    {
        $random = $this->createStub(Random::class);
        $random->method('getRandomString')->willReturnOnConsecutiveCalls('aaaaaaaa', 'bbbbbbbb');

        $generator = new ReferralCodeGenerator($random);

        $taken = ['AAAAAAAA' => true];
        $code = $generator->generateUnique(fn (string $candidate): bool => isset($taken[$candidate]));

        self::assertSame('BBBBBBBB', $code);
    }

    public function testGenerateUniqueUppercasesTheCandidate(): void
    {
        $random = $this->createStub(Random::class);
        $random->method('getRandomString')->willReturn('abc123de');

        $generator = new ReferralCodeGenerator($random);

        self::assertSame('ABC123DE', $generator->generateUnique(fn (): bool => false));
    }

    /**
     * Astronomically unlikely in practice (36^8 possible codes), but exhausting every retry
     * attempt must still return a code (a longer one) rather than looping forever or throwing.
     */
    public function testGenerateUniqueFallsBackToALongerCodeWhenEveryAttemptCollides(): void
    {
        $random = $this->createStub(Random::class);
        $random->method('getRandomString')->willReturnMap([
            [8, 'aaaaaaaa'],
            [12, 'bbbbbbbbbbbb'],
        ]);

        $generator = new ReferralCodeGenerator($random);

        self::assertSame('BBBBBBBBBBBB', $generator->generateUnique(fn (): bool => true));
    }
}
