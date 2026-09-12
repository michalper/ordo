<?php
declare(strict_types=1);

namespace Ordo\Automation\Controller\ProductFeed;

/**
 * Serves the Meta Catalog CSV feed at "/ordo/productfeed/metacatalog" — see AbstractFeedAction
 * for the shared serving logic every feed format route uses, and di.xml for how this class gets
 * wired to MetaCatalogFeedGenerator specifically.
 */
class MetaCatalog extends AbstractFeedAction
{
}
