<?php
declare(strict_types=1);

namespace Ordo\Automation\Controller\ProductFeed;

/**
 * Serves the Google Merchant Center feed at "/ordo/productfeed/index" — see
 * AbstractFeedAction for the shared serving logic every feed format route uses, and di.xml
 * for how this class gets wired to GoogleMerchantFeedGenerator specifically.
 */
class Index extends AbstractFeedAction
{
}
