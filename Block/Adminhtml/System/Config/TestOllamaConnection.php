<?php
declare(strict_types=1);

namespace Ordo\Automation\Block\Adminhtml\System\Config;

use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;

/**
 * "Test Ollama Connection" button renderer, same shape as Magento's own
 * Magento\MediaStorage\Block\System\Config\System\Storage\Media\Synchronize - a plain button
 * plus a small inline AJAX call to Controller\Adminhtml\Ai\TestConnection, with the result
 * written into a sibling <span> rather than a blocking alert() so it doesn't interrupt an admin
 * mid-edit of the surrounding form.
 */
class TestOllamaConnection extends Field
{
    protected $_template = 'Ordo_Automation::system/config/test_ollama_connection.phtml';

    public function render(AbstractElement $element)
    {
        $element->unsScope()->unsCanUseWebsiteValue()->unsCanUseDefaultValue();
        return parent::render($element);
    }

    protected function _getElementHtml(AbstractElement $element)
    {
        return $this->_toHtml();
    }

    public function getAjaxUrl(): string
    {
        return $this->getUrl('ordo/ai/testconnection');
    }
}
