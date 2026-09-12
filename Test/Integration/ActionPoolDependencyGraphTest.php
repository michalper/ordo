<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Integration;

use Magento\Framework\App\Bootstrap;
use Magento\Framework\ObjectManagerInterface;
use Ordo\Automation\Model\Campaign\ActionPool;
use PHPUnit\Framework\TestCase;

/**
 * Regression test for a real production-blocking bug: ActionPool's array argument constructs
 * every registered action eagerly (see etc/di.xml), and one of those actions (SendEmail/SendSms/
 * SendWhatsApp/SendPush) takes QuietHoursGate, which itself took CampaignDispatcher directly —
 * and CampaignDispatcher takes ActionPool. That's a genuine constructor cycle in the concrete
 * class graph (ActionPool -> Send* -> QuietHoursGate -> CampaignDispatcher -> ActionPool), which
 * a mock-based unit test can never catch (mocks never actually walk the real object graph). It
 * only ever surfaced when something actually constructed ActionPool for real — e.g. loading
 * Controller\Adminhtml\Campaign\Edit's Flow canvas — which threw "LogicException: Circular
 * dependency: ... ActionPool depends on ... CampaignDispatcher and vice versa" for every real
 * request, not just a corner case. Fixed by wiring QuietHoursGate's campaignDispatcher argument
 * to CampaignDispatcher\Proxy in etc/di.xml (same break-the-cycle-with-a-Proxy shape already used
 * for SegmentMatcher/ConditionGroupEvaluator right above it) — this test exists so a future
 * change re-introducing a direct (non-Proxy) edge anywhere in this cycle fails loudly here
 * instead of silently shipping a broken admin page again.
 */
class ActionPoolDependencyGraphTest extends TestCase
{
    private static ObjectManagerInterface $objectManager;

    public static function setUpBeforeClass(): void
    {
        require_once BP . '/app/bootstrap.php';
        $bootstrap = Bootstrap::create(BP, $_SERVER);
        self::$objectManager = $bootstrap->getObjectManager();
        self::$objectManager->get(\Magento\Framework\App\State::class)->setAreaCode('adminhtml');
    }

    public function testActionPoolConstructsWithoutACircularDependencyException(): void
    {
        $pool = self::$objectManager->get(ActionPool::class);

        self::assertNotEmpty(
            $pool->getAvailableTypes(),
            'every registered action type must still resolve once ActionPool is real, not mocked'
        );
    }
}
