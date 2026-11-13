<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\GraphQl;

use Ordo\Automation\Api\Data\ConsentChannelPreferenceInterface;
use Ordo\Automation\Api\Data\ConsentPreferencesInterface;

/**
 * The OrdoConsentPreferences/OrdoChannelConsent GraphQL shape, shared by every consent resolver
 * (the query and both mutations all return the same full, fresh preferences) - a thin adapter
 * from the Api layer's typed ConsentChannelPreferenceInterface getters onto GraphQL's own field
 * names, the same role OfferDataMapper plays for OrdoOffer.
 */
class ConsentPreferencesDataMapper
{
    /**
     * @return array{
     *     email: array{consented: bool, promotional: bool, account_updates: bool},
     *     sms: array{consented: bool, promotional: bool, account_updates: bool},
     *     push: array{consented: bool, promotional: bool, account_updates: bool},
     *     whatsapp: array{consented: bool, promotional: bool, account_updates: bool},
     *     rcs: array{consented: bool, promotional: bool, account_updates: bool},
     *     ads: bool
     * }
     */
    public function map(ConsentPreferencesInterface $preferences): array
    {
        return [
            'email' => $this->mapChannel($preferences->getEmail()),
            'sms' => $this->mapChannel($preferences->getSms()),
            'push' => $this->mapChannel($preferences->getPush()),
            'whatsapp' => $this->mapChannel($preferences->getWhatsapp()),
            'rcs' => $this->mapChannel($preferences->getRcs()),
            'ads' => $preferences->getAds(),
        ];
    }

    /**
     * @return array{consented: bool, promotional: bool, account_updates: bool}
     */
    private function mapChannel(ConsentChannelPreferenceInterface $channel): array
    {
        return [
            'consented' => $channel->getConsented(),
            'promotional' => $channel->getPromotional(),
            'account_updates' => $channel->getAccountUpdates(),
        ];
    }
}
