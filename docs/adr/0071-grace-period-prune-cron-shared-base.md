# ADR 0071: `AbstractGracePeriodPruneCron` for the 3 multi-bucket prune crons

## Status

Accepted.

## Context

Companion to ADR 0070, which found two distinct duplicated shapes across the six "prune" crons,
not one. ADR 0070's PR covered the first shape (`AbstractSingleColumnRetentionPruneCron`, for
`PruneCronRunLog`/`PruneMessageLog`/`PruneVisitorEvents`). This PR covers the second:
`PrunePendingPopups`, `PruneNotifications` and `PruneSurveyPrompts` each delete from more than one
condition bucket per run - an "acted upon, past a grace period" bucket, plus one or more "never
acted upon and expired" buckets whose `IS NULL` guards differ in count and column between the
three - too different a shape to share ADR 0070's base.

Within that second shape, only one piece turned out to be genuinely identical across all three:
the plain "acted upon, past grace" delete - `PendingPopups`' `delivered_at`, `Notifications`'
`read_at`, and `SurveyPrompts`' `responded_at` all delete rows where that column `IS NOT NULL` and
older than a grace cutoff, then log a count. `SurveyPrompts` has a second grace-based delete
(delivered-but-never-answered) that looks similar but needs one extra guard
(`responded_at IS NULL` - a row displayed long ago but answered recently must not be deleted),
so it doesn't fit the same shared shape. Each subclass's own "never acted upon and expired"
bucket(s) differ enough in `IS NULL` guards (`PendingPopups`/`Notifications` have one,
`SurveyPrompts` has one of a different shape again) that forcing them into a shared hook would
move the real logic behind a parameter list instead of removing duplication.

## Decision

`Cron/AbstractGracePeriodPruneCron`: a constructor taking the two dependencies all three share
(`ResourceConnection`, `CronRunLogger`, both `protected` so subclasses' own inline deletes can use
them directly), one abstract `tableName()` hook, and one concrete helper -
`deleteWherePastGrace(string $column, int $graceHours): int` - covering exactly the one
byte-identical bucket. Everything else (the "expired" buckets, `SurveyPrompts`' second grace
delete) stays inline in each subclass's own `execute()`.

`deleteWherePastGrace()` casts `strtotime()`'s result with `(int)`, matching
`PruneMessageLog`/`PruneVisitorEvents`' existing convention for a retention value PHPStan can't
resolve to a literal at analysis time: inside the three original classes, `self::GRACE_HOURS` was
a class constant PHPStan could fold into the `strtotime()` argument string and prove always valid;
as a method parameter here it's an opaque `int`, so PHPStan reports `int|false` instead of `int`
and needs the same explicit, narrow cast the day-based retention crons already use for the same
reason (not a suppression - `strtotime()` really can return `false` for a malformed string, and
this module already treats that risk as acceptable at this exact call shape).

Plain abstract base class, not a trait - same reasoning as ADR 0070: none of these three crons
extend anything else today.

## Consequences

- `PrunePendingPopups`/`PruneNotifications`/`PruneSurveyPrompts` each drop their one-line grace-
  cutoff computation plus one `delete()` call, replaced by one `deleteWherePastGrace()` call;
  their own "expired" bucket(s) and (for `SurveyPrompts`) second grace delete stay untouched.
- Combined with ADR 0070's PR, all six existing `Test/Unit/Cron/Prune*Test.php` files pass
  unmodified - no constructor signature changed, only which class owns the method bodies that
  used to be inline in `execute()`.
