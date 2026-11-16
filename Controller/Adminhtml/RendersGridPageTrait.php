<?php

declare(strict_types=1);

namespace Ordo\Automation\Controller\Adminhtml;

use Magento\Backend\Model\View\Result\Page;
use Magento\Framework\Phrase;

/**
 * Shared "build a result page, mark its active admin-menu node, set its title, return it" tail -
 * confirmed, byte-for-byte, across ~30 read-only grid/report landing-page controllers in this
 * module before this extraction. A trait, not an abstract base class, deliberately: those
 * controllers already extend one of ten different parents (Magento\Backend\App\Action directly,
 * or one of nine per-feature Abstract*Action classes shared by their own Save/Delete/etc.
 * siblings, which must keep providing ADMIN_RESOURCE to those other siblings) - a shared base
 * class here would force every using class to give up whichever parent it already has. A trait
 * composes into any of them unchanged; each using class keeps requiring a
 * `private readonly \Magento\Framework\View\Result\PageFactory $resultPageFactory` constructor
 * property, since a trait can't declare its own constructor.
 *
 * Also the one place that now fixes, once, two PHPStan gaps every one of those controllers used
 * to suppress independently in phpstan-baseline.neon: PageFactory::create()'s declared return
 * type is the plain framework Page, which doesn't declare setActiveMenu() - only the Backend
 * module's own Page subclass does, and that's always the real runtime type for an admin
 * controller's resultPageFactory (confirmed via reflection - Magento\Backend\Model\View\Result\
 * Page::setActiveMenu() exists, Magento\Framework\View\Result\Page does not) - narrowed in
 * execute() below via an inline doc comment, not a cast, same reasoning this module already
 * applies elsewhere for a framework interface it knows is backed by exactly one concrete class
 * in context. Title::prepend()'s own $prefix parameter has no native type hint at all (confirmed
 * via reflection), so passing the Phrase straight through here - matching every existing
 * caller's own test, which asserts prepend() is invoked with the Phrase object itself (from
 * __()), not its rendered string - needs no cast and raises no PHPStan error either.
 */
trait RendersGridPageTrait
{
    /**
     * @return string the admin-menu node to mark active (Ordo_Automation::... from menu.xml -
     *   not necessarily this controller's own ADMIN_RESOURCE; several intentionally activate a
     *   shared parent node instead of their own leaf).
     */
    abstract protected function activeMenu(): string;

    abstract protected function pageTitle(): Phrase;

    /**
     * Hook for a using class that needs to do something before the page itself is built
     * (Customer360\Index/Gdpr\Index's own email-lookup) - a no-op by default.
     */
    protected function beforeRender(): void
    {
    }

    public function execute()
    {
        $this->beforeRender();

        /** @var Page $resultPage */
        $resultPage = $this->resultPageFactory->create();
        $resultPage->setActiveMenu($this->activeMenu());
        $resultPage->getConfig()->getTitle()->prepend($this->pageTitle());

        return $resultPage;
    }
}
