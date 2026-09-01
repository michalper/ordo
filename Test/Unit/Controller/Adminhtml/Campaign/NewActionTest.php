<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Controller\Adminhtml\Campaign;

use Magento\Backend\App\Action\Context;
use Ordo\Automation\Test\Unit\Controller\AbstractAdminActionTestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;

class NewActionTest extends AbstractAdminActionTestCase
{
    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteForwardsToEdit(): void
    {
        $context = $this->makeContext();

        $controller = new class ($context) extends \Ordo\Automation\Controller\Adminhtml\Campaign\NewAction {
            public array $forwardedTo = [];

            protected function _forward($action, $controller = null, $module = null, ?array $params = null)
            {
                $this->forwardedTo[] = $action;

                return null;
            }
        };

        $controller->execute();

        self::assertSame(['edit'], $controller->forwardedTo);
    }
}
