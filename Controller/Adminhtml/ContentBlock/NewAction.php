<?php
declare(strict_types=1);

namespace Ordo\Automation\Controller\Adminhtml\ContentBlock;

use Magento\Framework\App\Action\HttpGetActionInterface;

class NewAction extends AbstractContentBlockAction implements HttpGetActionInterface
{
    public function execute()
    {
        return $this->_forward('edit');
    }
}
