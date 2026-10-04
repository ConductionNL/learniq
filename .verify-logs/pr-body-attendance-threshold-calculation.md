## Scope

Leerplicht 16-uur crossing could never fire, for two independently confirmed reasons plus a third found while implementing the fix. This PR ships in two independent tracks: (1) a fully declarative calculation that closes the pre-existing `attendance` capability spec requirement "Threshold crossing is a declared calculation trigger" outright, and (2) a guarded manual transition bridge that lets the already-written (but dead) `AttendanceFlagCreationHandler` actually create an `AttendanceFlag` today.

## Evidence and root causes

`learniq-defect-triage.md`, entry 2, plus one new finding:

1. **Layer 1 (declared, now fixed).** `AttendanceThreshold.x-openregister-notifications.thresholdCrossed` watches a field, `unexcusedLesuren`, that no calculation ever declared. Fixed: added `x-openregister-aggregations.unexcusedRecordCount` (per-cohort count of absent-unexcused `AttendanceRecord`s) and `x-openregister-calculations.unexcusedLesuren`/`isThresholdCrossed`, the same aggregate-then-compare shape `Regulation.coveragePercent`/`ragStatus` already use in this register. Also added `coordinator` to the notification's recipients, matching `onCross.notifyRoles`'s own documented default (`["mentor", "coordinator"]`).
2. **Layer 2 (platform gap, worked around).** `TransitionEngine` only dispatches `ObjectTransitionedEvent` from a genuinely-executed *named transition*, never from a bare `calculatedChange` evaluation — confirmed by reading `openregister/lib/Service/Lifecycle/TransitionEngine.php` end to end. No transition named or targeting `threshold-crossed` exists (or could exist as a real state — see below). Worked around with a guarded manual `check-threshold` self-loop transition (`active` → `active`, mirroring `Session.substitute-teacher`'s established self-loop precedent).
3. **New: a third bug in the existing, already-committed handler.** `AttendanceFlagCreationHandler` calls `$event->getContext()`. I read `openregister/lib/Event/ObjectTransitionedEvent.php`'s full public method list (`isAutomatic`/`getObject`/`getAction`/`getFrom`/`getTo`/`getUserId`/`getRegister`/`getSchema`) — there is no `getContext()` at all. Even if layer 2 were fixed alone, this handler would still throw the moment it ran (silently swallowed by `TransitionEngine::dispatchTransitioned()`'s catch-and-log boundary), and no flag would ever be created. Fixed by reading the crossing detail from `$event->getObject()->jsonSerialize()` instead.

## Named platform gap (not silently worked around)

The per-cohort aggregate is **not** a true per-individual-learner figure — `AttendanceThreshold` (a shared rule definition, not a per-learner instance) has no `learnerId` field, and OpenRegister's aggregation DSL resolves `@self.*` against the aggregating object's own fields only. There is no declarative way to express "this specific learner's unexcused lesuren" today. The guarded `check-threshold` transition is the documented bridge: a caller (a manual admin/mentor action today; a future scheduled per-learner job is the natural long-term caller, not built here) supplies the per-learner value as a transition input, and `AttendanceThresholdCrossingGuard` refuses the transition unless it meets the limit.

## M1 rows

Per the triage, row `4.7` ("16 uur in 4 weken detection with holiday exclusion") stays partial — the row's second cited gap, "no holiday exclusion," is untouched by this fix (no relative-date operator exists in the aggregation `where` DSL). Row `4.8` ("Melding to DUO verzuimloket") also stays partial, gated separately on the leerplicht job type/integriq delegation. Both rows improve in substance (the underlying crossing detection now genuinely works end-to-end for the case a caller can supply) without moving to `yes`, consistent with the triage's own prediction.

## What was verified (exit codes)

- `php -l` — exit 0 on all 3 touched/new PHP files
- Targeted `vendor/bin/phpcs` — 0 errors (1 pre-existing inherited warning)
- Targeted `vendor/bin/phpstan analyse` — 0 errors (caught and fixed one real issue mid-work: an unreachable `=== null` check after a `??` coalesce, since `checkedMetricValue` can never be null post-coalesce)
- Targeted `vendor/bin/phpmd` — 0 findings (the first pass had 3 real findings on `createFlag()`'s complexity/length, confirmed genuinely new against `origin/development`'s 0-finding baseline for the same file, fixed by extracting `extractCrossingDetail()`/`saveFlag()`)
- `vendor/bin/phpunit --filter 'AttendanceThreshold|AttendanceFlagCreationHandler'` — exit 0 (16 tests, 51 assertions) — **this is the test that proves a crossing that passes the guard genuinely creates an `AttendanceFlag`**, the explicit bar for this fix
- `npm run check:json-strict` / `check:register` — exit 0 (the register's guard-class cross-reference check caught the missing `AttendanceThresholdCrossingGuard` class before it was written, confirming the check actually works)
- `TMPDIR=<outside-repo> COMPOSER_PROCESS_TIMEOUT=0 composer check:strict` (via semaphore) — `ALL CHECKS PASSED`: lint/phpcs/phpmd/psalm/phpstan all 0 errors, full PHPUnit 1090 tests / 4841 assertions / 5 skipped
- `bash hydra/scripts/run-hydra-gates.sh --scope-to-diff --base origin/development` — 2 gates failed, both inherited/pre-existing (identical to #909/#918): `gate-112 newman-reach`; `gate-53 effective-manifest-crossref` (broken checker, `ReferenceError: require is not defined in ES module scope`, triggered because this diff touches a register JSON, not a finding about this PR's content)

## Inherited findings

`gate-112` and `gate-53` are pre-existing/tooling issues, already reported identically on PR #909/#918. Not fixed here.

🤖 Generated with [Claude Code](https://claude.com/claude-code)
