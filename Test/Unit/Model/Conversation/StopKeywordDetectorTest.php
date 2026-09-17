<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\Conversation;

use Ordo\Automation\Model\Conversation\StopKeywordDetector;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class StopKeywordDetectorTest extends TestCase
{
    private StopKeywordDetector $detector;

    protected function setUp(): void
    {
        $this->detector = new StopKeywordDetector();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function keywordProvider(): array
    {
        return [
            'STOP uppercase' => ['STOP'],
            'stop lowercase' => ['stop'],
            'Stop mixed case' => ['Stop'],
            'unsubscribe' => ['unsubscribe'],
            'UNSUBSCRIBE' => ['UNSUBSCRIBE'],
            'cancel' => ['cancel'],
            'CANCEL' => ['CANCEL'],
            'end' => ['end'],
            'END' => ['END'],
            'quit' => ['quit'],
            'QUIT' => ['QUIT'],
            'padded with whitespace' => ['  stop  '],
        ];
    }

    #[DataProvider('keywordProvider')]
    public function testMatchesEveryStopKeywordCaseInsensitively(string $body): void
    {
        self::assertTrue($this->detector->matches($body));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function nonKeywordProvider(): array
    {
        return [
            'ordinary reply' => ['Thanks, that helps!'],
            'contains stop as substring but is not the whole message' => ['please stop sending me these'],
            'empty body' => [''],
            'stop with trailing punctuation' => ['STOP!'],
            'START, the opposite intent' => ['START'],
        ];
    }

    #[DataProvider('nonKeywordProvider')]
    public function testDoesNotMatchNonKeywordBodies(string $body): void
    {
        self::assertFalse($this->detector->matches($body));
    }
}
