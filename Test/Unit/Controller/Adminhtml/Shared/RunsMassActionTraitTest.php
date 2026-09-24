<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Controller\Adminhtml\Shared;

use Magento\Backend\App\Action;
use Magento\Backend\Model\View\Result\Redirect;
use Magento\Framework\Phrase;
use Ordo\Automation\Controller\Adminhtml\Shared\RunsMassActionTrait;
use Ordo\Automation\Test\Unit\Controller\AbstractAdminActionTestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;

/**
 * Directly exercises RunsMassActionTrait's execute() loop via a minimal concrete controller, on
 * top of the per-entity MassEnable/MassDisable/MassDelete tests (ScoreRule\MassEnableTest et al.)
 * that already prove each real entity wires getMassActionCollection()/applyToEntity() correctly.
 */
class RunsMassActionTraitTest extends AbstractAdminActionTestCase
{
    private function makeController(iterable $collection): Action
    {
        return new class ($this->makeContext(), $collection) extends Action {
            use RunsMassActionTrait;

            public array $touched = [];

            public function __construct(
                \Magento\Backend\App\Action\Context $context,
                private readonly iterable $collection
            ) {
                parent::__construct($context);
            }

            protected function getMassActionCollection(): iterable
            {
                return $this->collection;
            }

            protected function applyToEntity(object $entity): void
            {
                $this->touched[] = $entity;
            }

            protected function getMassActionSuccessMessage(int $count): Phrase
            {
                return __('Processed %1.', $count);
            }
        };
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteAppliesToEveryEntityAndReportsCount(): void
    {
        $entityA = new \stdClass();
        $entityB = new \stdClass();
        $controller = $this->makeController([$entityA, $entityB]);

        $redirect = $this->createMock(Redirect::class);
        $redirect->method('setPath')->willReturnSelf();
        $this->resultRedirectFactory->method('create')->willReturn($redirect);

        $this->messageManager->expects(self::once())->method('addSuccessMessage')->with(self::callback(
            fn ($phrase) => (string) $phrase === 'Processed 2.'
        ));

        self::assertSame($redirect, $controller->execute());
        self::assertSame([$entityA, $entityB], $controller->touched);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteReportsZeroWhenCollectionEmpty(): void
    {
        $controller = $this->makeController([]);

        $redirect = $this->createMock(Redirect::class);
        $redirect->method('setPath')->willReturnSelf();
        $this->resultRedirectFactory->method('create')->willReturn($redirect);

        $this->messageManager->expects(self::once())->method('addSuccessMessage')->with(self::callback(
            fn ($phrase) => (string) $phrase === 'Processed 0.'
        ));

        self::assertSame($redirect, $controller->execute());
        self::assertSame([], $controller->touched);
    }
}
