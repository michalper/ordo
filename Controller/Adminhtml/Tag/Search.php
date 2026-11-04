<?php
declare(strict_types=1);

namespace Ordo\Automation\Controller\Adminhtml\Tag;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Ordo\Automation\Model\CustomerTagManager;

/**
 * Backs the tag-chip autocomplete used by both the segment editor (Has Tag / Visitor Has Tag
 * conditions) and the campaign Flow builder (add_tag action) - see
 * view/adminhtml/web/js/tag-chip-editor.js. URL is ordo/tag/search (this own Tag/ folder, not a
 * bare Controller/Adminhtml/TagSearch.php - Magento admin routing needs exactly
 * <route>/<folder>/<action>, confirmed the hard way: a folder-less controller 404s). Deliberately
 * NOT namespaced under Controller\Adminhtml\Segment (unlike ProductSearch, which IS duplicated
 * per-feature specifically to avoid cross-feature ACL coupling): tags aren't owned by either
 * feature, and gating this one shared endpoint behind Ordo_Automation::segments or ::campaigns
 * risks an admin role that has one but not the other getting a silent 403 on whichever page it
 * lacks. ADMIN_RESOURCE is the bare Magento_Backend::admin login check instead - this returns
 * nothing beyond tag strings a tagged customer's own admin-visible profile already shows.
 */
class Search extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'Magento_Backend::admin';

    /** Matches the picker's own page size - enough to be useful, small enough to stay fast. */
    private const int MAX_RESULTS = 20;

    public function __construct(
        Context $context,
        private readonly JsonFactory $resultJsonFactory,
        private readonly CustomerTagManager $customerTagManager
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $rawTerm = $this->getRequest()->getParam('term', '');
        $term = trim(is_string($rawTerm) ? $rawTerm : '');
        $result = $this->resultJsonFactory->create();

        if ($term === '') {
            return $result->setData(['items' => []]);
        }

        $tags = $this->customerTagManager->searchTags($term, self::MAX_RESULTS);

        return $result->setData(['items' => array_map(static fn (string $tag) => ['tag' => $tag], $tags)]);
    }
}
