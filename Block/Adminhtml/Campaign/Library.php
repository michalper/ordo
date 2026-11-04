<?php
declare(strict_types=1);

namespace Ordo\Automation\Block\Adminhtml\Campaign;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Ordo\Automation\Model\Campaign\TemplateLibrary;

/**
 * Feeds view/adminhtml/templates/campaign/library.phtml the curated template list and the URL
 * each template's "Use this Template" form posts to - see
 * Controller\Adminhtml\Campaign\ImportFromLibrary for what that POST actually does.
 */
class Library extends Template
{
    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        Context $context,
        private readonly TemplateLibrary $templateLibrary,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /**
     * @return array<int, array{id: string, name: string, description: string, payload: array<string, mixed>}>
     */
    public function getTemplates(): array
    {
        return $this->templateLibrary->getTemplates();
    }

    public function getImportUrl(): string
    {
        return $this->getUrl('*/*/importfromlibrary');
    }

    public function getBackUrl(): string
    {
        return $this->getUrl('*/*/index');
    }
}
