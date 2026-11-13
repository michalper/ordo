<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\GraphQl\Resolver;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Exception\GraphQlAuthorizationException;
use Magento\Framework\GraphQl\Exception\GraphQlInputException;
use Magento\Framework\GraphQl\Exception\GraphQlNoSuchEntityException;
use Magento\Framework\GraphQl\Query\Resolver\ContextInterface as BaseContextInterface;
use Magento\Framework\GraphQl\Query\ResolverInterface;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Ordo\Automation\Api\ConsentPreferencesManagementInterface;
use Ordo\Automation\Model\GraphQl\ConsentPreferencesDataMapper;
use Ordo\Automation\Model\GraphQl\CustomerIdResolver;

/**
 * GraphQL counterpart of the storefront preferences page's per-topic toggle
 * (Controller\Consent\SetTopic) and REST PUT
 * /V1/ordo/consent-preferences/mine/topic/:channel/:topic - all three call
 * ConsentPreferencesManagementInterface::setMyTopicConsent(). Same input-vs-business-rule
 * reasoning as Resolver\SetConsent's own docblock.
 */
class SetConsentTopic implements ResolverInterface
{
    public function __construct(
        private readonly ConsentPreferencesManagementInterface $consentPreferencesManagement,
        private readonly ConsentPreferencesDataMapper $consentPreferencesDataMapper,
        private readonly CustomerIdResolver $customerIdResolver
    ) {
    }

    /**
     * @param array<string, mixed>|null $value
     * @param array{channel?: string, topic?: string, consented?: bool}|null $args
     * @inheritDoc
     */
    public function resolve(
        Field $field,
        $context,
        ResolveInfo $info,
        ?array $value = null,
        ?array $args = null
    ) {
        /** @var BaseContextInterface $context */
        if ($this->customerIdResolver->resolveCustomerId($context) === null) {
            throw new GraphQlAuthorizationException(__('The current customer isn\'t authorized.'));
        }

        $channel = (string) ($args['channel'] ?? '');
        $topic = (string) ($args['topic'] ?? '');
        $consented = (bool) ($args['consented'] ?? false);

        try {
            $preferences = $this->consentPreferencesManagement->setMyTopicConsent($channel, $topic, $consented);
        } catch (NoSuchEntityException $e) {
            throw new GraphQlNoSuchEntityException(__($e->getMessage()));
        } catch (LocalizedException $e) {
            throw new GraphQlInputException(__($e->getMessage()));
        }

        return $this->consentPreferencesDataMapper->map($preferences);
    }
}
