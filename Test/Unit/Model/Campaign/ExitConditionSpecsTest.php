<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\Campaign;

use Ordo\Automation\Model\Campaign\ExitConditionSpecs;
use PHPUnit\Framework\TestCase;

class ExitConditionSpecsTest extends TestCase
{
    public function testDecodeReturnsEmptyArrayForNull(): void
    {
        self::assertSame([], ExitConditionSpecs::decode(null));
    }

    public function testDecodeReturnsEmptyArrayForEmptyString(): void
    {
        self::assertSame([], ExitConditionSpecs::decode(''));
    }

    public function testDecodeReturnsEmptyArrayForMalformedJson(): void
    {
        self::assertSame([], ExitConditionSpecs::decode('{not valid json'));
    }

    public function testDecodeReturnsEmptyArrayWhenTopLevelIsNotAList(): void
    {
        self::assertSame([], ExitConditionSpecs::decode('{"type": "has_converted"}'));
    }

    public function testDecodeDropsRowsMissingAUsableType(): void
    {
        $json = json_encode([
            ['params' => ['foo' => 'bar']],
            ['type' => ''],
            ['type' => 123],
            'not-an-array',
        ]);

        self::assertSame([], ExitConditionSpecs::decode($json));
    }

    public function testDecodeNormalizesValidRows(): void
    {
        $json = json_encode([
            ['type' => 'has_converted', 'params' => ['within_days' => 7]],
            ['type' => 'order_total_gte'],
        ]);

        self::assertSame(
            [
                ['type' => 'has_converted', 'params' => ['within_days' => 7]],
                ['type' => 'order_total_gte', 'params' => []],
            ],
            ExitConditionSpecs::decode($json)
        );
    }

    public function testSanitizeForSaveReturnsNullForEmptyInput(): void
    {
        self::assertNull(ExitConditionSpecs::sanitizeForSave(''));
    }

    public function testSanitizeForSaveReturnsNullWhenEveryRowIsMalformed(): void
    {
        self::assertNull(ExitConditionSpecs::sanitizeForSave(json_encode([['params' => []]])));
    }

    public function testSanitizeForSaveReEncodesOnlyTheValidRows(): void
    {
        $raw = json_encode([
            ['type' => 'has_converted', 'params' => ['within_days' => 7]],
            ['type' => ''],
        ]);

        self::assertSame(
            json_encode([['type' => 'has_converted', 'params' => ['within_days' => 7]]]),
            ExitConditionSpecs::sanitizeForSave($raw)
        );
    }
}
