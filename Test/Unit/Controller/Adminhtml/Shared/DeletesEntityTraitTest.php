<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Controller\Adminhtml\Shared;

use Magento\Backend\App\Action;
use Magento\Backend\Model\View\Result\Redirect;
use Magento\Framework\Phrase;
use Ordo\Automation\Controller\Adminhtml\Shared\DeletesEntityTrait;
use Ordo\Automation\Test\Unit\Controller\AbstractAdminActionTestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;

/**
 * Directly exercises DeletesEntityTrait's execute() loop via a minimal concrete controller, on
 * top of the per-entity Delete tests (ScoreRule\DeleteTest et al.) that already prove each real
 * entity wires deleteEntity()/the message hooks correctly.
 */
class DeletesEntityTraitTest extends AbstractAdminActionTestCase
{
    private function makeController(): Action
    {
        return new class ($this->makeContext()) extends Action {
            use DeletesEntityTrait;

            public array $deletedIds = [];
            public bool $shouldThrow = false;

            protected function deleteEntity(int $entityId): void
            {
                if ($this->shouldThrow) {
                    throw new \RuntimeException('boom');
                }
                $this->deletedIds[] = $entityId;
            }

            protected function getMissingIdMessage(): Phrase
            {
                return __('Missing id.');
            }

            protected function getDeletedMessage(): Phrase
            {
                return __('Deleted.');
            }

            protected function getDeleteErrorMessage(\Throwable $e): Phrase
            {
                return __('Error: %1', $e->getMessage());
            }
        };
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteRedirectsWithErrorWhenEntityIdMissing(): void
    {
        $controller = $this->makeController();
        $this->request->method('getParam')->willReturnMap([['entity_id', null]]);

        $redirect = $this->createMock(Redirect::class);
        $redirect->method('setPath')->willReturnSelf();
        $this->resultRedirectFactory->method('create')->willReturn($redirect);

        $this->messageManager->expects(self::once())->method('addErrorMessage')->with(self::callback(
            fn ($phrase) => (string) $phrase === 'Missing id.'
        ));

        self::assertSame($redirect, $controller->execute());
        self::assertSame([], $controller->deletedIds);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteDeletesAndAddsSuccessMessage(): void
    {
        $controller = $this->makeController();
        $this->request->method('getParam')->willReturnMap([['entity_id', 5]]);

        $redirect = $this->createMock(Redirect::class);
        $redirect->method('setPath')->willReturnSelf();
        $this->resultRedirectFactory->method('create')->willReturn($redirect);

        $this->messageManager->expects(self::once())->method('addSuccessMessage')->with(self::callback(
            fn ($phrase) => (string) $phrase === 'Deleted.'
        ));

        self::assertSame($redirect, $controller->execute());
        self::assertSame([5], $controller->deletedIds);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteCastsStringEntityIdToInt(): void
    {
        $controller = $this->makeController();
        $this->request->method('getParam')->willReturnMap([['entity_id', '5abc']]);

        $redirect = $this->createMock(Redirect::class);
        $redirect->method('setPath')->willReturnSelf();
        $this->resultRedirectFactory->method('create')->willReturn($redirect);

        self::assertSame($redirect, $controller->execute());
        self::assertSame([5], $controller->deletedIds);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteAddsErrorMessageWhenDeleteThrows(): void
    {
        $controller = $this->makeController();
        $controller->shouldThrow = true;
        $this->request->method('getParam')->willReturnMap([['entity_id', 5]]);

        $redirect = $this->createMock(Redirect::class);
        $redirect->method('setPath')->willReturnSelf();
        $this->resultRedirectFactory->method('create')->willReturn($redirect);

        $this->messageManager->expects(self::once())->method('addErrorMessage')->with(self::callback(
            fn ($phrase) => (string) $phrase === 'Error: boom'
        ));

        self::assertSame($redirect, $controller->execute());
        self::assertSame([], $controller->deletedIds);
    }
}
