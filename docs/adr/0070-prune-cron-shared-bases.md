# ADR 0070: Two shared bases for the six "prune" crons

## Status

Accepted.

## Context

A `/simplify`-style pass over the six append-only-log/queue pruning crons
(`PruneCronRunLog`, `PruneMessageLog`, `PruneVisitorEvents`, `PrunePendingPopups`,
`PruneNotifications`, `PruneSurveyPrompts`) found two distinct duplicated shapes, not one:

- `PruneCronRunLog`/`PruneMessageLog`/`PruneVisitorEvents` were byte-for-byte identical: compute a
  cutoff from a retention window, delete every row on one table where one timestamp column is
  older than it, log a one-line summary. Only the table, the column, where the retention window
  comes from (a fixed constant vs. `Helper\Config`), and the log line's noun phrase differ.
- `PrunePendingPopups`/`PruneNotifications`/`PruneSurveyPrompts` delete from two or three separate
  condition buckets per run instead of one (an "acted upon, past a grace period" bucket, plus one
  or more "never acted upon and expired" buckets whose `IS NULL` guards genuinely differ in count
  and column between the three) - a materially different shape, not a variant of the first one.

Forcing both shapes into a single generic base (e.g. an arbitrary list of delete-rule tuples)
would need a small rule interpreter to cover the second shape's varying bucket counts, trading
three readable `execute()` bodies for one hard-to-read generic one - not a win for three call
sites.

## Decision

Two separate bases:

- `AbstractSingleColumnRetentionPruneCron` (`tableName()`, `timestampColumn()`, `retentionDays()`,
  `rowNounPhrase()`) for the first group. `PruneCronRunLog` needs no `Config` dependency (fixed
  30-day constant); `PruneMessageLog`/`PruneVisitorEvents` each inject their own `Config` and read
  it in `retentionDays()` - the base class constructor only takes what's common
  (`ResourceConnection`, `CronRunLogger`).
- `AbstractGracePeriodPruneCron` (`tableName()` plus a protected `deleteWherePastGrace(string
  $column, int $graceHours)` helper) for the second group. Only the plain "acted-upon, past
  grace" bucket - identical in `PendingPopups`' `delivered_at` delete, `Notifications`' `read_at`
  delete, and `SurveyPrompts`' `responded_at` delete - is pulled into the helper.
  `SurveyPrompts`' own second grace-based delete (delivered-but-never-answered) needs one extra
  guard (`responded_at IS NULL`, so a row displayed long ago but answered recently isn't deleted)
  the plain bucket doesn't have, so it stays inline - same as each subclass's own "never acted
  upon and expired" bucket(s), which differ enough between the three (different `IS NULL` guards)
  that forcing them into the same hook would just move the real logic behind a parameter list
  instead of removing duplication.

Plain abstract base classes, not traits: none of these six crons extend anything else today, so
there's no parent-hierarchy conflict to compose around (contrast
`RendersGridPageTrait`/`RendersEditPageTrait`, which exist as traits specifically because their
8-32 using controllers each already extend a different per-feature `Abstract*Action`).

Landed as two PRs: this one introduces `AbstractSingleColumnRetentionPruneCron` and migrates its
three subclasses; a companion PR introduces `AbstractGracePeriodPruneCron` and migrates the other
three, independently reviewable and revertable.

## Consequences

- `PruneCronRunLog`/`PruneMessageLog`/`PruneVisitorEvents`'s `execute()` bodies collapse to four
  one-line hook methods each; the cutoff-compute-delete-log shape now exists in exactly one place.
- `PrunePendingPopups`/`PruneNotifications`/`PruneSurveyPrompts` keep their own `execute()` (the
  real per-class logic), minus the four now-shared grace-cutoff-and-delete calls.
- All six existing `Test/Unit/Cron/Prune*Test.php` files pass unmodified, across both PRs - no
  constructor signature changed, only which class owns the method bodies that used to be inline
  in `execute()`.
