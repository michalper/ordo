<?php
declare(strict_types=1);

namespace Ordo\Automation\Controller\Referral;

use Magento\Customer\Model\Session as CustomerSession;
use Magento\Customer\Model\Url as CustomerUrl;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\UrlInterface;
use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\ReferralManager;

/**
 * Returns the logged-in customer's own referral code (creating one on first request) plus the
 * ready-to-share link, as JSON - a theme wires this into My Account via a small AJAX call, same
 * "thin endpoint, no bespoke template" shape as this module's other Controller\Track\* trackers.
 */
class MyCode extends AbstractReferralAction implements HttpGetActionInterface
{
    public function __construct(
        Context $context,
        CustomerSession $customerSession,
        CustomerUrl $customerUrl,
        private readonly JsonFactory $resultJsonFactory,
        private readonly ReferralManager $referralManager,
        private readonly Config $config,
        private readonly UrlInterface $url
    ) {
        parent::__construct($context, $customerSession, $customerUrl);
    }

    public function execute(): ResultInterface
    {
        $result = $this->resultJsonFactory->create();

        if (!$this->config->isReferralEnabled()) {
            return $result->setData(['ok' => false, 'reason' => 'referral_disabled']);
        }

        $customerId = (int) $this->customerSession->getCustomerId();
        $code = $this->referralManager->getOrCreateCode($customerId);

        return $result->setData([
            'ok' => true,
            'code' => $code,
            'share_url' => $this->url->getUrl('ordo/referral/track', ['_query' => ['ref' => $code]]),
        ]);
    }
}
