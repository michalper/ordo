<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\Track;

use Magento\Checkout\Model\Session as CheckoutSession;

/**
 * Wraps CheckoutSession::getLastRealOrderId() - a magic accessor
 * (Magento\Framework\Session\SessionManager::__call(), not a method declared anywhere in its own
 * class hierarchy) that PHPUnit can't stub directly on a mock of CheckoutSession itself
 * (MockBuilder::addMethods(), the mechanism for exactly this case, was removed in PHPUnit 10+).
 * This one-line wrapper exists purely so Controller\Track\CrossSell's own unit tests can mock a
 * real, declared method instead - see Test/Integration/LastRealOrderIdReaderTest.php for this
 * class's own coverage, against a real session.
 */
class LastRealOrderIdReader
{
    public function __construct(private readonly CheckoutSession $checkoutSession)
    {
    }

    public function getLastRealOrderId(): string
    {
        return (string) $this->checkoutSession->getLastRealOrderId();
    }
}
