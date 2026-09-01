<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model;

use Ordo\Automation\Api\CampaignRepositoryInterface;
use Ordo\Automation\Api\OfferRepositoryInterface;
use Ordo\Automation\Model\CampaignRepository;
use Ordo\Automation\Model\OfferRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Regression guard: repository implementations must declare the exact same
 * return type as their interface. PHP's engine only enforces this in the
 * opposite direction implicitly for typed interfaces — omitting the return
 * type on the implementing class (e.g. getList()) is a fatal "Declaration
 * must be compatible" error at class-load time, found by actually running
 * setup:di:compile against a real Magento install.
 */
class RepositorySignatureCompatibilityTest extends TestCase
{
    #[DataProvider('repositoryProvider')]
    public function testImplementationReturnTypesMatchInterface(string $interface, string $implementation): void
    {
        foreach ((new \ReflectionClass($interface))->getMethods() as $interfaceMethod) {
            $implMethod = new \ReflectionMethod($implementation, $interfaceMethod->getName());

            $interfaceReturnType = $interfaceMethod->getReturnType();
            $implReturnType = $implMethod->getReturnType();

            $this->assertNotNull(
                $implReturnType,
                sprintf(
                    '%s::%s() must declare a return type matching %s::%s().',
                    $implementation,
                    $interfaceMethod->getName(),
                    $interface,
                    $interfaceMethod->getName()
                )
            );

            $this->assertSame(
                (string) $interfaceReturnType,
                (string) $implReturnType,
                sprintf(
                    '%s::%s() return type "%s" does not match %s::%s() return type "%s".',
                    $implementation,
                    $interfaceMethod->getName(),
                    (string) $implReturnType,
                    $interface,
                    $interfaceMethod->getName(),
                    (string) $interfaceReturnType
                )
            );
        }
    }

    public static function repositoryProvider(): array
    {
        return [
            'CampaignRepository' => [CampaignRepositoryInterface::class, CampaignRepository::class],
            'OfferRepository' => [OfferRepositoryInterface::class, OfferRepository::class],
        ];
    }
}
