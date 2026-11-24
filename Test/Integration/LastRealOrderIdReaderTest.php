<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Integration;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\Bootstrap;
use Magento\Framework\ObjectManagerInterface;
use Ordo\Automation\Model\Track\LastRealOrderIdReader;
use PHPUnit\Framework\TestCase;

/**
 * CheckoutSession::getLastRealOrderId() is a magic accessor
 * (Magento\Framework\Session\SessionManager::__call(), not a method declared anywhere in its own
 * class hierarchy) that PHPUnit can't stub on a mock directly - see LastRealOrderIdReader's own
 * docblock. This real-object-manager test covers that one line instead, against a real
 * CheckoutSession.
 */
class LastRealOrderIdReaderTest extends TestCase
{
    private static ObjectManagerInterface $objectManager;

    public static function setUpBeforeClass(): void
    {
        require_once BP . '/app/bootstrap.php';
        $bootstrap = Bootstrap::create(BP, $_SERVER);
        self::$objectManager = $bootstrap->getObjectManager();
    }

    public function testGetLastRealOrderIdReadsTheRealCheckoutSessionValue(): void
    {
        $checkoutSession = self::$objectManager->get(CheckoutSession::class);
        $checkoutSession->setLastRealOrderId('100000123');

        $reader = self::$objectManager->create(LastRealOrderIdReader::class);

        self::assertSame('100000123', $reader->getLastRealOrderId());
    }

    public function testGetLastRealOrderIdReturnsEmptyStringWhenNeverSet(): void
    {
        $checkoutSession = self::$objectManager->get(CheckoutSession::class);
        $checkoutSession->unsLastRealOrderId();

        $reader = self::$objectManager->create(LastRealOrderIdReader::class);

        self::assertSame('', $reader->getLastRealOrderId());
    }
}
