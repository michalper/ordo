<?php
declare(strict_types=1);

namespace Ordo\Automation\Controller\ProductFeed;

/**
 * Serves the AI-agent JSON feed at "/ordo/productfeed/aiagent" — see AbstractFeedAction for the
 * shared serving logic every feed format route uses, and di.xml for how this class gets wired to
 * AiAgentFeedGenerator specifically.
 */
class AiAgent extends AbstractFeedAction
{
}
