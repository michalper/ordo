<?php
declare(strict_types=1);

namespace Ordo\Automation\Api;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Ordo\Automation\Api\Data\ConsentPreferencesInterface;

/**
 * Customer-facing self-service consent management — the storefront/headless counterpart of
 * Controller\Adminhtml\Gdpr\{Index,SetConsent,SetTopicConsent}, scoped to the authenticated
 * customer's own consent rather than an admin looking up an arbitrary customer by email. Every
 * transport (REST, GraphQL, the plain storefront account page) calls this one service - no
 * consent logic duplicated into a resolver or controller, the same rule every other GraphQl\
 * Resolver in this module already follows.
 */
interface ConsentPreferencesManagementInterface
{
    /**
     * @return \Ordo\Automation\Api\Data\ConsentPreferencesInterface
     * @throws NoSuchEntityException if the caller isn't an authenticated customer
     */
    public function getMyPreferences(): ConsentPreferencesInterface;

    /**
     * @param string $channel Model\ConsentChannel value (email/sms/push/whatsapp/rcs/ads)
     * @param bool $consented
     * @return \Ordo\Automation\Api\Data\ConsentPreferencesInterface
     * @throws NoSuchEntityException if the caller isn't an authenticated customer
     * @throws LocalizedException if $channel isn't a real ConsentChannel value
     */
    public function setMyChannelConsent(string $channel, bool $consented): ConsentPreferencesInterface;

    /**
     * @param string $channel Model\ConsentChannel value - a message-delivery channel only
     *     (email/sms/push/whatsapp/rcs), never "ads", which has no topic concept
     * @param string $topic Model\ConsentTopic value (promotional/account_updates)
     * @param bool $consented
     * @return \Ordo\Automation\Api\Data\ConsentPreferencesInterface
     * @throws NoSuchEntityException if the caller isn't an authenticated customer
     * @throws LocalizedException if $channel/$topic aren't real values
     */
    public function setMyTopicConsent(string $channel, string $topic, bool $consented): ConsentPreferencesInterface;
}
