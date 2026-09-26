<?php
declare(strict_types=1);

namespace Ordo\Automation\Api;

use Ordo\Automation\Api\Data\AiAgentQuoteResultInterface;

/**
 * Backs `POST /V1/ordo/ai-agent/quote` (etc/webapi.xml, `<resource ref="anonymous"/>` - see
 * Plugin\AiAgent\AuthenticateQuoteRequestPlugin for the actual API-key auth + rate limiting that
 * anonymous ACL doesn't give this route). Third and final step of AI-agent commerce readiness
 * (GEO): the `ai_agent` product feed (#227) tells an agent WHAT this store sells, this tells it
 * what a specific basket would actually cost right now, including shipping - the same
 * "assemble already-existing calculators into one response" shape as
 * Model\Customer360\Customer360SnapshotBuilder, except the underlying calculator here (a real
 * Magento\Quote\Model\Quote, price/tax/discount/shipping engines and all) is core Magento's own,
 * built fresh per request and never persisted - this is a price check, not a cart.
 */
interface AiAgentQuoteManagementInterface
{
    /**
     * @param \Ordo\Automation\Api\Data\AiAgentQuoteItemInterface[] $items sku+qty pairs - at
     *   least one must match a real, salable product or an InputException is thrown; unmatched
     *   SKUs among otherwise-valid ones are skipped and reported back in
     *   AiAgentQuoteResultInterface::getUnmatchedSkus(), not treated as a request failure.
     * @param string $countryId ISO-2 shipping destination country, e.g. "US", "PL".
     * @param string $postcode Shipping destination postcode.
     * @param string|null $region Shipping destination region/state, where applicable.
     * @return \Ordo\Automation\Api\Data\AiAgentQuoteResultInterface
     */
    public function getQuote(
        array $items,
        string $countryId,
        string $postcode,
        ?string $region = null
    ): AiAgentQuoteResultInterface;
}
