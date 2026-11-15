---
kind: code
depends_on: []
---

# Proposal: sessions-from-planninq

## Summary
Learniq stops being the place a school timetable lives. When planninq is installed, learniq reads lessons from planninq through a timetable source adapter; its own `Session` schema stays as the fallback for schools without planninq and for sessions a teacher creates by hand. A `timetable-import` job no longer writes learniq `Session` rows when planninq is the target: it asks integriq to deliver the rostering source into planninq and records what planninq did. The cohort timetable and my timetable pages read through the adapter, and the conflict detector scans the adapter's lessons.

## Motivation
Decision D10 (learniq round 1, `market-intelligence/learniq/_round1/compare/decisions.md`, Ruben, 2026-09-27): planninq owns the timetable, the integriq adapter delivers into planninq, learniq reads sessions from there. D25 says it is built now.

Today three learniq surfaces read or write `Session` directly:
- `TimetableImportHandler` calls `api/sources/timetable-import/run` on integriq, a route integriq never served (its own docblock says so), and upserts learniq `Session` rows from the answer. No timetable has ever arrived that way.
- `CohortTimetableView` queries OpenRegister's `session` schema for the cohort.
- `TimetableController::mine()` loads `Session` rows per cohort for my timetable.

Corpus: M3 row `I11` (`_round1/compare/M3-integrations.md`): a live Zermelo, Untis or Xedule timetable koppeling at every VO, MBO and HE incumbent surveyed (vo-las#11.7, mbo-he-sis#11.7, "volautomatische roosterkoppeling met Zermelo of Xedule" elo#11.7). Section (b): "today learniq has no timetable-import adapter at all, only an import job type."

## Affected Projects
- [x] Project: `learniq`: timetable source adapter (interface, local and planninq implementations, resolver), the planninq branch of `TimetableImportHandler`, the conflict detector on adapter data, a cohort timetable endpoint, both timetable pages.
- [ ] Project: `planninq`: producer, `school-timetable-target` (planninq #685).
- [ ] Project: `integriq`: producer, `rostering-adapter-targets-planninq` (integriq PR of this lane).

## Scope

### In Scope
- `TimetableSource` interface with `LocalSessionTimetableSource` (learniq `Session`) and `PlanninqTimetableSource` (planninq's `TimetableSessionsQueryEvent`, looked up by name), and `TimetableSourceResolver` (planninq when installed, unless the app config `timetable_source` is `learniq`).
- `TimetableImportHandler`: when planninq is the source, a `timetable-import` job dispatches integriq's `RosterImportRequestedEvent`, records the counts and rejections on the job, writes no `Session`, and hands the delivered window to the conflict detector. Without planninq, the current path is unchanged.
- `TimetableConflictDetector::scanWindow()` over lessons the caller already loaded; `SessionOverlapEvaluator` reads a planninq lesson's own teacher account and room code.
- `GET /api/timetable/cohort/{cohortId}` through the adapter; `GET /api/timetable/mine` through the adapter.
- `CohortTimetableView` and `MyTimetable` read those endpoints; a planninq lesson is shown but not opened as a learniq session.
- Tests with a stubbed planninq and a stubbed integriq, both verbatim copies of the real event classes.

### Out of Scope
- Retiring the local `Session` schema: it stays for schools without planninq and for hand-made sessions (brief).
- Substitutions and cancellations on planninq lessons: the `cancel` and `substitute-teacher` transitions stay on learniq `Session`. A planninq lesson shows the status its rostering system sent.
- Linking learniq cohorts to school group codes. Integriq's target configuration holds that map; the job may pass one in `scope.groupMap`.
- Moving `DataExchangeJob` to integriq (decision D7, lane r3-exchange). If that lands first, the planninq branch moves with the job handler; the adapter and the pages do not depend on it.

## Approach
One interface, two implementations, one resolver; every reader asks the resolver. Planninq and integriq are reached through their typed events (ADR-041), looked up by name, so learniq runs without either. Details in design.md.

## New Dependencies
None.

## Impact
- New: `lib/Timetabling/Source/{TimetableSource,LocalSessionTimetableSource,PlanninqTimetableSource,TimetableSourceResolver}.php`, `lib/Timetabling/PlanninqTimetableImport.php`.
- Changed: `lib/Timetabling/TimetableImportHandler.php`, `TimetableConflictDetector.php`, `SessionOverlapEvaluator.php`, `lib/Controller/TimetableController.php`, `appinfo/routes.php`, `src/views/CohortTimetableView.vue`, `src/views/MyTimetable.vue`, `src/api/timetable.js`, `l10n`.
- No schema change.

## Cross-Project Dependencies
Reads planninq contract v1 (`TimetableSessionsQueryEvent`) and integriq contract v1 (`RosterImportRequestedEvent`). All three PRs can land in any order: without planninq the local path runs; with planninq but without integriq a planninq-target import fails with a clear message instead of writing sessions.

Touches the same `TimetableImportHandler` as lane r3-exchange's `data-exchange-to-integriq`; named in the PR body so the landing can order them.

## Risks

### Risk 1: A planninq lesson has no learniq cohort
**Severity:** Medium. **Mitigation:** the cohort timetable reads by cohort id; a lesson whose group code is not mapped does not appear there until integriq's group map links it. The PR body and docs say so.

### Risk 2: Conflict rows point at planninq ids
**Severity:** Low. **Mitigation:** the conflict queue already stores bare ids; the lesson titles come from the scan. Opening a planninq lesson from the queue is out of scope.

### Risk 3: Overlap with r3-exchange
**Severity:** Medium. **Mitigation:** the planninq branch is one small class (`PlanninqTimetableImport`) the handler calls; it moves as a unit.

## Rollback Strategy
Revert the merge commit, or set `timetable_source` to `learniq` to fall back without a deploy.

## Open Questions
None.
