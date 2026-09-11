<?php
declare(strict_types=1);

namespace Ordo\Automation\Controller\Adminhtml\Segment;

use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\View\Result\PageFactory;

/**
 * "Segment overlap" admin page — lets an admin pick any two existing segments and see how many
 * customers match both (avoiding message fatigue from campaigns targeting overlapping audiences),
 * closing the ROADMAP.md "no segment overlap/venn analysis" gap. Purely a form + AJAX result view;
 * the actual computation (Controller\Adminhtml\Segment\OverlapCompute) reuses
 * Model\Segment\SegmentMemberResolver::getMatchingCustomerIds() twice and does the
 * intersect/diff in PHP — no new resolver logic needed.
 */
class Overlap extends AbstractSegmentAction implements HttpGetActionInterface
{
    public function __construct(
        Context $context,
        private readonly PageFactory $resultPageFactory
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $resultPage = $this->resultPageFactory->create();
        $resultPage->setActiveMenu('Ordo_Automation::campaigns');
        $resultPage->getConfig()->getTitle()->prepend(__('Segment Overlap'));

        return $resultPage;
    }
}
