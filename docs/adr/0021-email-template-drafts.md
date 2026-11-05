# ADR 0021: Email template drafts

## Status

Accepted.

## Context

ROADMAP.md's "Candidate new features" list asked for the remaining half of email template
versioning: `Model\EmailTemplateVersion` (snapshot-on-save + restore, see `docs/adr`'s own earlier
work) already gives history, but there was no way to edit a template's content as a work-in-progress
without immediately changing what `send_email` sends - every edit to a core `Magento\Email\Model\Template`
row is live the instant it's saved.

Templates live entirely in Magento core's `email_template` table; this module has never had its
own email template entity. `Model\Campaign\Action\SendEmail` passes a free-text `template`
identifier straight to core's `Magento\Framework\Mail\Template\TransportBuilder`, which always
resolves against whatever is in the **live** row at send time.

## Decision

**A separate, mutable `ordo_email_template_draft` table - never a flag/status on the live
`email_template` row.** The live row is physically never written to until an explicit "Publish"
action. This is the whole safety property the feature needs: `send_email`/`TransportBuilder`
cannot read draft content because there is no code path that lets it, not because of any added
guard - `SendEmail.php` needed zero changes. A flag-on-the-live-row design would have had to
convince `TransportBuilder` (core, unowned) to skip unpublished rows, which isn't realistically
possible without forking core behavior.

**One open draft per `template_id`**, enforced by a real unique DB constraint
(`ORDO_EMAIL_TEMPLATE_DRAFT_TEMPLATE_ID`), not just app-level validation.
`Controller\Adminhtml\EmailTemplateDraft\NewAction` looks up an existing draft via
`Model\ResourceModel\EmailTemplateDraft::load($draft, $templateId, EmailTemplateDraft::TEMPLATE_ID)`
- `AbstractDb::load()`'s own by-arbitrary-field form, the same mechanism `Edit`/`Save` already use
by `entity_id` - rather than a `Collection` lookup (`getFirstItem()`/`getColumnValues()` both force
a real `load()`/DB round trip a single-row existence check has no reason to pay for).

**Publish reuses `Controller\Adminhtml\EmailTemplateVersion\MassRestore`'s exact pattern**: load
the live `Magento\Email\Model\Template` by id, overwrite `template_subject`/`template_text`/
`template_styles`, save through the real `Magento\Email\Model\ResourceModel\Template`. Saving
through the real core resource model (not a raw `UPDATE`) automatically re-triggers
`Plugin\Email\SnapshotEmailTemplateVersion` when versioning is enabled, so every publish gets a
free "state before this publish" history point with zero new wiring - proven directly in
`Test\Integration\EmailTemplateDraftScenarioTest`.

**Publish deletes the draft row** rather than tracking a "published" status. There's no
pending-diff concept to maintain: editing the same template again after publishing just starts a
fresh draft from the now-current live content via `NewAction`'s own copy-on-create logic. This
also keeps the drafts grid showing only genuinely open, unpublished work.

**No new config toggle, approval workflow, options-source class, or plugin.** Unlike email
template versioning (off by default, a real behavior change applied to every template save on
every code path), a draft has no live-system side effect to gate - the live row is only ever
touched by an explicit, admin-initiated Publish click, so there's nothing to turn on or off.
Scope matches the ROADMAP item's own "medium scope" framing; this intentionally leaves out
approval workflows, AJAX content pre-fill (the copy happens server-side at creation time instead),
a draft-vs-live diff view, and multiple drafts per template.

**The "which template to draft" chooser is a plain block + `.phtml`, not a new
`Config\Source\*` options class.** `Block\Adminhtml\EmailTemplateDraft\Chooser::getUndraftedTemplates()`
feeds a `<select>` directly from `Magento\Email\Model\ResourceModel\Template\CollectionFactory`
filtered against already-drafted `template_id`s - a `Magento\Framework\Data\OptionSourceInterface`
class exists for ui_component `<select>` fields and grid filters, neither of which this one-off
GET form is.

**The grid's actions column (`Ui\Component\Listing\Column\EmailTemplateDraftActions`) is a
standalone `Column` subclass, not an `AbstractEntityActionsColumn` subclass.** That shared base
(used by every other entity's edit/delete grid actions) hardcodes exactly two actions and reads
the delete-confirm text's entity name off `$item['name']` - this entity has no `name` field (its
display field is `template_code`) and needs a third action, Publish. It still reuses the exact
same already-translated `"Delete %1 \"%2\""`/`"Are you sure you want to delete this %1?"` phrases
every other entity's delete confirm uses, just sourced from `template_code`.

**Layout handle naming**: `Controller\Adminhtml\EmailTemplateDraft\Index` is genuinely named
`index`, so `ordo_emailtemplatedraft_index.xml` correctly carries the `_index` suffix - unlike the
bug `docs/adr/0020-campaign-template-library.md` found and fixed, where two **non**-`index`-named
actions (`library`, `importform`) had an erroneous `_index` suffix that silently broke their own
content rendering.

**Grid actions column URLs must be fully-qualified, not `*/*/` wildcards.** Found by actually
clicking Publish in the real admin UI: `Ui\Component\Listing\Column\EmailTemplateDraftActions`'s
Edit/Publish/Delete links 404'd to `mui/index/...`. A `*/*/` wildcard resolves against the
*current request's own route* - inside the grid's own AJAX data-source request
(`mui/index/render`), that's `mui/index`, not the page the grid is displayed on. Fixed by using
the fully-qualified `ordo/emailtemplatedraft/...` paths, the same convention
`Ui\Component\Listing\Column\AbstractEntityActionsColumn`'s `getEditUrlPath()`/`getDeleteUrlPath()`
already use for every other entity's grid actions column.

**Found and fixed a second, separate, genuinely pre-existing bug this way**: every entity's
shared `Block\Adminhtml\Shared\Edit\DeleteButton` (and this feature's own new `PublishButton`)
called `deleteConfirm('message', 'url')` - the 2-argument form, which navigates via a plain GET
(`mage/adminhtml/globals.js`'s `setLocation()`). Every entity's `Delete` controller in this module
implements `HttpPostActionInterface` only, and `app/etc/di.xml`'s HTTP-method-to-interface map
rejects a GET to a POST-only controller before it ever dispatches - 404, not a framework quirk,
by design (CSRF hardening). This means the edit-form "Delete" button has never actually worked for
*any* entity in this module when clicked from the edit page itself (only the grid's own row-action
Delete, which already used a correct AJAX POST, ever worked). Fixed at the shared class, not just
for this feature's own `PublishButton`: both now call `deleteConfirm('message', 'url', {data: {}})`
- the 3-argument form (`mage/dataPost.js`) that builds and submits a real POST form with the
page's own `form_key` auto-attached, the same pattern Magento core's own
`Magento\Backend\Block\Widget\Form\Container` uses for its generic Delete button. A closely
related instance was found but deliberately **not** fixed in this pass, since it's unrelated to
email template drafts: `WhatsAppTemplate\Edit\SubmitForReviewButton` and
`WhatsAppTemplate\Edit\RefreshStatusButton` have the exact same 2-argument `deleteConfirm()` bug
against their own `HttpPostActionInterface`-only controllers - logged as its own ROADMAP.md item.
