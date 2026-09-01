<?php
declare(strict_types=1);

namespace Ordo\Automation\Controller\Track;

use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Controller\Result\JsonFactory;
use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\ResourceModel\PendingPopup\CollectionFactory as PendingPopupCollectionFactory;

/**
 * Public, unauthenticated endpoint the frontend JS tracker polls (view/frontend/web/js/
 * tracker.js), same trust model as Controller\Track\Event — no CSRF token, callable by an
 * anonymous visitor with no session/form key yet.
 *
 * Claims (sets delivered_at) the moment a row is handed out, not before — the same
 * claim-before-use pattern as Cron\RunScheduledCampaignActions — so two near-simultaneous polls
 * (e.g. two open tabs) can never both receive the same popup.
 */
class Popup extends Action implements HttpGetActionInterface, CsrfAwareActionInterface
{
    public function __construct(
        Context $context,
        private readonly JsonFactory $resultJsonFactory,
        private readonly PendingPopupCollectionFactory $pendingPopupCollectionFactory,
        private readonly ResourceConnection $resourceConnection,
        private readonly CustomerSession $customerSession,
        private readonly Config $config
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $result = $this->resultJsonFactory->create();

        if (!$this->config->isPopupEnabled()) {
            return $result->setData(['popup' => null]);
        }

        $visitorId = (string) $this->getRequest()->getParam('visitor_id');
        $customerId = $this->customerSession->isLoggedIn() ? (int) $this->customerSession->getCustomerId() : null;

        if ($visitorId === '' && $customerId === null) {
            return $result->setData(['popup' => null]);
        }

        $now = date('Y-m-d H:i:s');
        $collection = $this->pendingPopupCollectionFactory->create();
        $collection->addTargetFilter($customerId, $visitorId !== '' ? $visitorId : null, $now);
        // A handful of candidates, not just one — if two near-simultaneous polls both raced to
        // claim the first candidate, the loser must fall through to the next one instead of
        // coming back empty despite a popup genuinely being available.
        $collection->setPageSize(5);

        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName('ordo_pending_popup');

        foreach ($collection as $popup) {
            // The actual claim: an UPDATE conditioned on delivered_at still being NULL, not a
            // blind save of the in-memory model — two concurrent requests can both load the same
            // row from the SELECT above, but only one of their UPDATEs can match this WHERE
            // clause, so only one ever gets a non-zero affected-row count back.
            $claimed = $connection->update(
                $table,
                ['delivered_at' => $now],
                ['entity_id = ?' => (int) $popup->getId(), 'delivered_at IS NULL']
            );

            if ($claimed > 0) {
                return $result->setData([
                    'popup' => [
                        'headline' => $popup->getHeadline(),
                        'body' => $popup->getBody(),
                        'cta_label' => $popup->getCtaLabel(),
                        'cta_url' => $popup->getCtaUrl(),
                    ],
                ]);
            }
        }

        return $result->setData(['popup' => null]);
    }

    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true;
    }
}
