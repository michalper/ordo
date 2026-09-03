<?php
declare(strict_types=1);

namespace Ordo\Automation\Controller\Offer;

use Magento\Customer\Model\Session as CustomerSession;
use Magento\Customer\Model\Url as CustomerUrl;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Ordo\Automation\Model\OfferManagement;

/**
 * Handles the "Extend" button on the storefront "My Offers" page. Ownership and the extension
 * policy (Offer::canSelfExtend()) are enforced by OfferManagement::selfExtend() itself — this
 * controller just adapts that call to a POST form and flash messages.
 */
class Extend extends AbstractOfferAction implements HttpPostActionInterface
{
    public function __construct(
        Context $context,
        CustomerSession $customerSession,
        CustomerUrl $customerUrl,
        private readonly OfferManagement $offerManagement
    ) {
        parent::__construct($context, $customerSession, $customerUrl);
    }

    public function execute()
    {
        $offerId = (int) $this->getRequest()->getParam('offer_id');

        try {
            if ($offerId <= 0) {
                throw new LocalizedException(__('Invalid offer.'));
            }

            $offer = $this->offerManagement->selfExtend($offerId);
            $this->messageManager->addSuccessMessage(
                __('Offer "%1" has been extended to %2.', $offer->getReference(), $offer->getExpiresAt())
            );
        } catch (NoSuchEntityException) {
            $this->messageManager->addErrorMessage(__('This offer could not be found.'));
        } catch (LocalizedException $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
        }

        /** @var Redirect $resultRedirect */
        $resultRedirect = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT);
        return $resultRedirect->setPath('ordo/offer/index');
    }
}
