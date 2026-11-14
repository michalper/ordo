<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\Wallet;

use Magento\Framework\Exception\LocalizedException;
use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\Wallet\GoogleWalletJwtBuilder;
use Ordo\Automation\Model\Wallet\GoogleWalletLoyaltyObjectBuilder;
use Ordo\Automation\Model\Wallet\GoogleWalletPassLinkBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class GoogleWalletPassLinkBuilderTest extends TestCase
{
    private GoogleWalletLoyaltyObjectBuilder $loyaltyObjectBuilder;
    private GoogleWalletJwtBuilder $jwtBuilder;
    private Config $config;
    private GoogleWalletPassLinkBuilder $builder;

    protected function setUp(): void
    {
        $this->loyaltyObjectBuilder = $this->createMock(GoogleWalletLoyaltyObjectBuilder::class);
        $this->jwtBuilder = $this->createMock(GoogleWalletJwtBuilder::class);
        $this->config = $this->createStub(Config::class);

        $this->builder = new GoogleWalletPassLinkBuilder($this->loyaltyObjectBuilder, $this->jwtBuilder, $this->config);
    }

    private function configureFullyConfigured(): void
    {
        $this->config->method('isWalletPassEnabled')->willReturn(true);
        $this->config->method('getWalletPassGoogleIssuerId')->willReturn('issuer123');
        $this->config->method('getWalletPassGoogleClassId')->willReturn('rewards');
        $this->config->method('getWalletPassGoogleServiceAccountEmail')->willReturn('svc@example.com');
        $this->config->method('getWalletPassGooglePrivateKey')->willReturn('-----BEGIN PRIVATE KEY-----...');
    }

    public function testBuildsTheSaveUrlWhenFullyConfigured(): void
    {
        $this->configureFullyConfigured();

        $loyaltyObject = ['id' => 'issuer123.42'];
        $this->loyaltyObjectBuilder->expects(self::once())->method('build')->with(42)->willReturn($loyaltyObject);
        $this->jwtBuilder->expects(self::once())->method('buildSaveUrl')
            ->with($loyaltyObject, 'svc@example.com', '-----BEGIN PRIVATE KEY-----...')
            ->willReturn('https://pay.google.com/gp/v/save/jwt123');

        self::assertSame('https://pay.google.com/gp/v/save/jwt123', $this->builder->buildSaveUrl(42));
    }

    public function testThrowsWhenTheFeatureIsDisabled(): void
    {
        $this->config->method('isWalletPassEnabled')->willReturn(false);

        $this->loyaltyObjectBuilder->expects(self::never())->method('build');
        $this->jwtBuilder->expects(self::never())->method('buildSaveUrl');
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Loyalty wallet pass is not enabled.');

        $this->builder->buildSaveUrl(42);
    }

    /**
     * @return iterable<string, array{issuerId: string, classId: string, email: string, key: string}>
     */
    public static function incompleteConfigurationProvider(): iterable
    {
        yield 'missing issuer id' => ['issuerId' => '', 'classId' => 'rewards', 'email' => 'svc@example.com', 'key' => 'pem'];
        yield 'missing class id' => ['issuerId' => 'issuer123', 'classId' => '', 'email' => 'svc@example.com', 'key' => 'pem'];
        yield 'missing service account email' => ['issuerId' => 'issuer123', 'classId' => 'rewards', 'email' => '', 'key' => 'pem'];
        yield 'missing private key' => ['issuerId' => 'issuer123', 'classId' => 'rewards', 'email' => 'svc@example.com', 'key' => ''];
    }

    #[DataProvider('incompleteConfigurationProvider')]
    public function testThrowsWhenAnyGoogleWalletCredentialIsMissing(
        string $issuerId,
        string $classId,
        string $email,
        string $key
    ): void {
        $this->config->method('isWalletPassEnabled')->willReturn(true);
        $this->config->method('getWalletPassGoogleIssuerId')->willReturn($issuerId);
        $this->config->method('getWalletPassGoogleClassId')->willReturn($classId);
        $this->config->method('getWalletPassGoogleServiceAccountEmail')->willReturn($email);
        $this->config->method('getWalletPassGooglePrivateKey')->willReturn($key);

        $this->loyaltyObjectBuilder->expects(self::never())->method('build');
        $this->jwtBuilder->expects(self::never())->method('buildSaveUrl');
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Google Wallet is not fully configured.');

        $this->builder->buildSaveUrl(42);
    }
}
