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
use Ordo\Automation\Model\GraphQl\Resolver\SetConsentTopic;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

class SetConsentTopicTest extends TestCase
{
    private ConsentPreferencesManagementInterface $consentPreferencesManagement;

    protected function setUp(): void
    {
        $this->consentPreferencesManagement = $this->createMock(ConsentPreferencesManagementInterface::class);
    }

    private function resolve(array $args, ?int $userId = 42): mixed
    {
        $resolver = new SetConsentTopic(
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
            ->setAds(false);
        $this->consentPreferencesManagement->expects(self::once())->method('setMyTopicConsent')
            ->with('push', 'account_updates', false)
            ->willReturn($preferences);

        $result = $this->resolve(['channel' => 'push', 'topic' => 'account_updates', 'consented' => false]);

        self::assertFalse($result['ads']);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testThrowsGraphQlAuthorizationExceptionForAGuest(): void
    {
        $this->expectException(GraphQlAuthorizationException::class);

        $this->resolve(['channel' => 'push', 'topic' => 'promotional', 'consented' => false], userId: 0);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testThrowsGraphQlInputExceptionForAnInvalidTopic(): void
    {
        $this->consentPreferencesManagement->method('setMyTopicConsent')
            ->willThrowException(new LocalizedException(__('"email"/"bogus" is not a valid consent channel/topic pair.')));

        $this->expectException(GraphQlInputException::class);

        $this->resolve(['channel' => 'email', 'topic' => 'bogus', 'consented' => true]);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testThrowsGraphQlNoSuchEntityExceptionWhenManagementThrows(): void
    {
        $this->consentPreferencesManagement->method('setMyTopicConsent')
            ->willThrowException(new NoSuchEntityException(__('No authenticated customer for this request.')));

        $this->expectException(GraphQlNoSuchEntityException::class);

        $this->resolve(['channel' => 'email', 'topic' => 'promotional', 'consented' => true]);
    }
}
