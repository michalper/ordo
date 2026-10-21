<?php
declare(strict_types=1);

namespace Ordo\Automation\Controller\Track;

use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\ResourceModel\SurveyPrompt\CollectionFactory as SurveyPromptCollectionFactory;
use Ordo\Automation\Model\SurveyPrompt;
use Ordo\Automation\Model\Track\DeliveryClaimer;
use Ordo\Automation\Model\Track\VisitorIdentityResolver;

/**
 * Public, unauthenticated endpoint the frontend JS tracker polls, same trust model as
 * Controller\Track\Popup. Claims (sets delivered_at) the moment a row is handed out, not before —
 * same claim-before-use pattern as Controller\Track\Popup (Model\Track\DeliveryClaimer) — so two
 * near-simultaneous polls can never both receive the same prompt.
 */
class Survey extends Action implements HttpGetActionInterface, CsrfAwareActionInterface
{
    public function __construct(
        Context $context,
        private readonly JsonFactory $resultJsonFactory,
        private readonly SurveyPromptCollectionFactory $surveyPromptCollectionFactory,
        private readonly DeliveryClaimer $deliveryClaimer,
        private readonly VisitorIdentityResolver $visitorIdentityResolver,
        private readonly Config $config
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $result = $this->resultJsonFactory->create();

        if (!$this->config->isNpsSurveyEnabled()) {
            return $result->setData(['survey' => null]);
        }

        $visitorId = $this->visitorIdentityResolver->resolveVisitorIdFromParam($this->getRequest());
        $customerId = $this->visitorIdentityResolver->resolveCustomerId();

        if ($this->visitorIdentityResolver->hasNoIdentity($visitorId)) {
            return $result->setData(['survey' => null]);
        }

        $now = date('Y-m-d H:i:s');
        $collection = $this->surveyPromptCollectionFactory->create();
        $collection->addTargetFilter($customerId, $visitorId !== '' ? $visitorId : null, $now);
        // A handful of candidates, not just one — same claim-race reasoning as Controller\Track\
        // Popup.
        $collection->setPageSize(5);

        /** @var SurveyPrompt|null $prompt */
        $prompt = $this->deliveryClaimer->claimFirst($collection, 'ordo_survey_prompt', $now);
        if ($prompt !== null) {
            return $result->setData([
                'survey' => [
                    'id' => (int) $prompt->getId(),
                    'question' => $prompt->getQuestion(),
                ],
            ]);
        }

        return $result->setData(['survey' => null]);
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
