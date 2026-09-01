<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model;

use Magento\Framework\Model\AbstractModel;
use Ordo\Automation\Model\Campaign;
use Ordo\Automation\Model\Offer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Regression guard: setEntityId() overrides must stay parameter-compatible with
 * AbstractModel::setEntityId($entityId), which has no parameter type.
 *
 * Declaring `int $entityId` here is a PHP fatal "Declaration must be compatible"
 * error at class-load time (caught when Magento first autoloads these classes,
 * e.g. during setup:di:compile) — a plain mocked unit test would never load the
 * real class and would miss it, so this test asserts on live reflection instead.
 */
class EntityModelSignatureCompatibilityTest extends TestCase
{
    #[DataProvider('modelClassProvider')]
    public function testSetEntityIdParameterHasNoStricterTypeThanParent(string $modelClass): void
    {
        $parentParam = (new \ReflectionMethod(AbstractModel::class, 'setEntityId'))->getParameters()[0];
        $this->assertNull(
            $parentParam->getType(),
            'Precondition changed: AbstractModel::setEntityId no longer has an untyped parameter.'
        );

        $childParam = (new \ReflectionMethod($modelClass, 'setEntityId'))->getParameters()[0];
        $this->assertNull(
            $childParam->getType(),
            sprintf(
                '%s::setEntityId() declares a parameter type, which is incompatible with '
                . 'AbstractModel::setEntityId($entityId) and fatals at class-load time.',
                $modelClass
            )
        );
    }

    public static function modelClassProvider(): array
    {
        return [
            'Campaign' => [Campaign::class],
            'Offer' => [Offer::class],
        ];
    }
}
