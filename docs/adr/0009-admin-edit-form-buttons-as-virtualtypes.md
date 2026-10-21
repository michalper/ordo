# ADR 0009: Parametrize admin edit-form buttons via virtualTypes, not per-entity subclasses

## Status

Accepted.

## Context

Eight admin entities (AdAudience, Campaign, ContentBlock, FreeGiftOffer, LeadRoutingRule,
ScoreRule, Segment, WhatsAppTemplate) each had four `Block/Adminhtml/<Entity>/Edit/*Button.php`
classes — 32 in all. Every BackButton was byte-identical; the Save/SaveAndContinue/Delete
variants differed only by the save label, the ui-component form target
(`<form>.<form>`), or the delete-confirm message. The entity-specific extra buttons
(RefreshRss, RefreshStatus, SubmitForReview) genuinely differ and stay as real classes.

Two ways to collapse the duplication were on the table:

1. Four parametrized shared classes plus one thin real subclass per entity per button type
   (24 near-empty classes whose only job is to pass constructor strings).
2. Four parametrized shared classes plus `virtualType` entries in `etc/adminhtml/di.xml`,
   referenced by name from each form XML's `<button class="...">`.

## Decision

Option 2. A ui_component `<button class="...">` is resolved through
`Magento\Framework\View\Element\UiComponent\Control\ButtonProviderFactory::create()`, which
calls `ObjectManager::create()`; the object manager's factory maps the requested name through
`Config::getInstanceType()`, which is exactly where virtualType names resolve, and the
`instanceof ButtonProviderInterface` check runs against the instantiated object. The XSD types
the attribute as plain `xs:string`. So a virtualType name is a first-class citizen there, and
option 1's 24 subclasses would exist only to encode three strings each — configuration data,
which belongs in DI configuration.

The four shared classes live in `Block/Adminhtml/Shared/Edit/` next to the already-shared
`GenericButton`. `BackButton` takes no per-entity strings, so the form XMLs reference the class
directly; the other three are wired as `OrdoAutomation<Entity><Kind>Button` virtualTypes.
These are the module's first virtualTypes.

## Consequences

- 32 classes and their 32 near-identical unit tests collapse into 4 classes and 5 test files;
  adding a ninth entity's buttons is three di.xml entries, not four new classes plus tests.
- Nothing executes the XML wiring in a unit run, so
  `Test/Unit/Block/Adminhtml/Shared/Edit/ButtonWiringTest.php` parses the form XMLs and
  `etc/adminhtml/di.xml` and asserts every referenced button resolves and carries the strings
  the old per-entity classes hardcoded.
- Button labels/messages now live as di.xml strings and reach `__()` as variables, so
  `i18n:collect-phrases` won't pick them up from PHP; the phrases were already present in every
  `i18n/*.csv`, and new ones must be added there by hand.
