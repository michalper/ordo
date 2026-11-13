<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\GraphQl\Resolver;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Exception\GraphQlAuthorizationException;
use Magento\Framework\GraphQl\Exception\GraphQlInputException;
use Magento\Framework\GraphQl\Exception\GraphQlNoSuchEntityException;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Magento\GraphQl\Model\Query\ContextInterface;
use Ordo\Automation\Api\ConsentPreferencesManagementInterface;
use Ordo\Automation\Model\ConsentChannelPreference;
use Ordo\Automation\Model\ConsentPreferences;
use Ordo\Automation\Model\GraphQl\ConsentPreferencesDataMapper;
use Ordo\Automation\Model\GraphQl\CustomerIdResolver;
use Ordo\Automation\Model\GraphQl\Resolver\SetConsent;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

class SetConsentTest extends TestCase
{
    private ConsentPreferencesManagementInterface $consentPreferencesManagement;

    protected function setUp(): void
    {
        $this->consentPreferencesManagement = $this->createMock(ConsentPreferencesManagementInterface::class);
    }

    private function resolve(array $args, ?int $userId = 42): mixed
    {
        $resolver = new SetConsent(
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
            $args
        );
    }

    public function testDelegatesToManagementAndReturnsTheMappedPreferences(): void
    {
        $preferences = (new ConsentPreferences())
            ->setEmail(new ConsentChannelPreference())
            ->setSms(new ConsentChannelPreference())
            ->setPush(new ConsentChannelPreference())
            ->setWhatsapp(new ConsentChannelPreference())
            ->setRcs(new ConsentChannelPreference())
            ->setAds(true);
        $this->consentPreferencesManagement->expects(self::once())->method('setMyChannelConsent')
            ->with('sms', false)
            ->willReturn($preferences);

        $result = $this->resolve(['channel' => 'sms', 'consented' => false]);

        self::assertTrue($result['ads']);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testThrowsGraphQlAuthorizationExceptionForAGuest(): void
    {
        $this->expectException(GraphQlAuthorizationException::class);

        $this->resolve(['channel' => 'sms', 'consented' => false], userId: 0);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testThrowsGraphQlInputExceptionForAnInvalidChannel(): void
    {
        $this->consentPreferencesManagement->method('setMyChannelConsent')
            ->willThrowException(new LocalizedException(__('"bogus" is not a valid consent channel.')));

        $this->expectException(GraphQlInputException::class);

        $this->resolve(['channel' => 'bogus', 'consented' => true]);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testThrowsGraphQlNoSuchEntityExceptionWhenManagementThrows(): void
    {
        $this->consentPreferencesManagement->method('setMyChannelConsent')
            ->willThrowException(new NoSuchEntityException(__('No authenticated customer for this request.')));

        $this->expectException(GraphQlNoSuchEntityException::class);

        $this->resolve(['channel' => 'sms', 'consented' => true]);
    }
}
