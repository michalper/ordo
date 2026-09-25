<?php
declare(strict_types=1);

namespace Ordo\Automation\Plugin\Email;

use Magento\Email\Model\ResourceModel\Template as TemplateResource;
use Magento\Framework\Model\AbstractModel;
use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\EmailTemplateVersion;
use Ordo\Automation\Model\EmailTemplateVersionFactory;
use Ordo\Automation\Model\ResourceModel\EmailTemplateVersion as EmailTemplateVersionResource;

/**
 * Snapshots a Magento email_template row's content every time it's saved - closes the "Email
 * template versioning/drafts" ROADMAP.md candidate's version-history half. Magento's own
 * template editor (Marketing > Email Templates) has no history of its own: saving overwrites the
 * row in place, so a template send_email references could be broken by an admin's edit with no
 * way back short of a database restore. This plugs the resource model directly (not an event,
 * see this class's own tests) so it fires regardless of which controller/code path saved the
 * template - the same real-save point Controller\Adminhtml\EmailTemplateVersion\Restore's own
 * save (loading a past version back onto the live template) also goes through, creating a fresh
 * "before you restored" version in the process.
 */
class SnapshotEmailTemplateVersion
{
    public function __construct(
        private readonly Config $config,
        private readonly EmailTemplateVersionFactory $emailTemplateVersionFactory,
        private readonly EmailTemplateVersionResource $emailTemplateVersionResource
    ) {
    }

    public function afterSave(TemplateResource $subject, TemplateResource $result, AbstractModel $object): TemplateResource
    {
        if (!$this->config->isEmailTemplateVersioningEnabled()) {
            return $result;
        }

        /** @var EmailTemplateVersion $version */
        $version = $this->emailTemplateVersionFactory->create();
        $version->setTemplateId((int) $object->getId());
        $version->setTemplateCode((string) $object->getData('template_code'));
        $version->setTemplateSubject((string) $object->getData('template_subject'));
        $version->setTemplateText((string) $object->getData('template_text'));
        $templateStyles = $object->getData('template_styles');
        $version->setTemplateStyles($templateStyles === null ? null : (string) $templateStyles);
        $this->emailTemplateVersionResource->save($version);

        return $result;
    }
}
