<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\GraphQl\Resolver;

use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Exception\GraphQlAuthorizationException;
use Magento\Framework\GraphQl\Exception\GraphQlNoSuchEntityException;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Magento\GraphQl\Model\Query\ContextInterface;
use Ordo\Automation\Api\ConsentPreferencesManagementInterface;
use Ordo\Automation\Model\ConsentChannelPreference;
use Ordo\Automation\Model\ConsentPreferences;
use Ordo\Automation\Model\GraphQl\ConsentPreferencesDataMapper;
use Ordo\Automation\Model\GraphQl\CustomerIdResolver;
use Ordo\Automation\Model\GraphQl\Resolver\ConsentPreferences as ConsentPreferencesResolver;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

class ConsentPreferencesTest extends TestCase
{
    private ConsentPreferencesManagementInterface $consentPreferencesManagement;

    protected function setUp(): void
    {
        $this->consentPreferencesManagement = $this->createMock(ConsentPreferencesManagementInterface::class);
    }

    private function resolve(?int $userId): mixed
    {
        $resolver = new ConsentPreferencesResolver(
            $this->consentPreferencesManagement,
            new ConsentPreferencesDataMapper(),
            new CustomerIdResolver()
        );

        $context = $this->createStub(ContextInterface::class);
        $context->method('getUserId')->willReturn($userId);

        return $resolver->resolve(
            $this->createStub(Field::class),
            $context,
            $this->createStub(ResolveInfo::class),
            null,
            null
        );
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testReturnsMappedPreferencesForALoggedInCustomer(): void
    {
        $preferences = (new ConsentPreferences())
            ->setEmail((new ConsentChannelPreference())->setConsented(true)->setPromotional(false)->setAccountUpdates(true))
            ->setSms(new ConsentChannelPreference())
            ->setPush(new ConsentChannelPreference())
            ->setWhatsapp(new ConsentChannelPreference())
            ->setRcs(new ConsentChannelPreference())
            ->setAds(true);
        $this->consentPreferencesManagement->method('getMyPreferences')->willReturn($preferences);

        $result = $this->resolve(42);

        self::assertTrue($result['email']['consented']);
        self::assertTrue($result['ads']);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testThrowsGraphQlAuthorizationExceptionForAGuest(): void
    {
        $this->expectException(GraphQlAuthorizationException::class);

        $this->resolve(0);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testThrowsGraphQlNoSuchEntityExceptionWhenManagementThrows(): void
    {
        $this->consentPreferencesManagement->method('getMyPreferences')
            ->willThrowException(new NoSuchEntityException(__('No authenticated customer for this request.')));

        $this->expectException(GraphQlNoSuchEntityException::class);

        $this->resolve(42);
    }
}
