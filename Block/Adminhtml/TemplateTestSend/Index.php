<?php
declare(strict_types=1);

namespace Ordo\Automation\Block\Adminhtml\TemplateTestSend;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Ordo\Automation\Model\Config\Source\WhatsAppTemplateOptions;

/**
 * View model for the Template Test Send page (view/adminhtml/templates/templatetestsend/index.phtml,
 * Controller\Adminhtml\TemplateTestSend\Index) - supplies the WhatsApp template picker's options
 * and the AJAX send endpoint URL; template-test-send.js does the actual fetch + result rendering.
 */
class Index extends Template
{
    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        Context $context,
        private readonly WhatsAppTemplateOptions $whatsAppTemplateOptions,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /**
     * @return array<int, array{value: int, label: string}>
     */
    public function getWhatsAppTemplateOptions(): array
    {
        return $this->whatsAppTemplateOptions->toOptionArray();
    }

    public function getSendUrl(): string
    {
        return $this->getUrl('*/*/send');
    }
}
