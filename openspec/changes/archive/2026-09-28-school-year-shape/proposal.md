---
kind: config
---

# Proposal: school-year-shape

## Summary
`ReportPeriod` carries an academic year and periods but no holidays or study days; `Sessions` has only a flat list view; `TimetableConflictQueue` has no per-teacher filter (round-1 findings 1.9, 5.4, 11.1, all NICE tier). This change adds `holidays`/`studyDays` to `ReportPeriod`, and two today/week-scoped `Sessions` index views (`SessionsToday`, `SessionsThisWeek`) using the manifest's existing `@today`/`@today+Nd` filter-token grammar (the same mechanism already used by `LessonIndex`/`Submissions`/etc.'s `@route.*`-scoped index pages in this manifest, confirmed by reading `useSelfFetchList.js`'s `resolveFilterMap` → `resolveFilterTokens` path). The `TimetableConflictQueue` per-teacher filter is **not** built here: that page is a genuinely registered custom Vue component (`src/views/TimetableConflictQueue.vue`, confirmed present in `src/registry.js` — unlike `CohortTimetable`'s dead `CnTimelineView`), so a UI filter control there is a `code` change, not `config`, and this change is declared `kind: config` per ADR-032. See Out of Scope.

## Motivation
Gibbon/Untis/TimeEdit/ParnasSys/ESIS all model a school year with holidays and study days feeding attendance/urentelling calculations (`placement.md` row 1.9: "gibbon: gibbonSchoolYear/Term/SpecialDay"; "untis: Dagroosterbeheer... marking holidays and teacher vacation periods"). `ReportPeriod` already has `startDate`/`endDate` for the AttendanceRecord summarisation window (per its own schema description) but nothing carves holidays out of that window. Separately, `Sessions` (row 5.4) has no week/day-scoped view — the nearest attempt, `CohortTimetable`, is a dead custom page (defect D01, a different lane's fix) and is explicitly out of scope here per the brief. `TimetableConflictQueue` (row 11.1) has no per-teacher filter; `TimetableConflictDetector`'s conflicts are teacher/room/cohort-scoped, so a coordinator managing one teacher's clashes today sees the whole queue.

## Affected Projects
- [x] Project: `learniq` — `lib/Settings/learniq_register.json` (`ReportPeriod` gains additive `holidays`/`studyDays`); `src/manifest.d/learning.json` (two new `Sessions`-scoped index pages + menu entries).

## Scope

### In Scope
- `ReportPeriod.holidays` — additive array of `{ name, startDate, endDate }`, default `[]`.
- `ReportPeriod.studyDays` — additive array of `{ date, description }`, default `[]`.
- `SessionsToday` and `SessionsThisWeek` — two new `type: index` pages on the `Session` schema, each with a `config.filter.startsAt` scoped via the manifest's `@today`/`@today+Nd` token grammar, plus menu entries under the existing `GroupTimetabling` menu group.
- A register-JSON unit test for the `ReportPeriod` additions.

### Out of Scope
- A per-teacher filter control on `TimetableConflictQueue` — that page is a registered custom Vue component (`src/views/TimetableConflictQueue.vue`), so adding a filter UI there is a `code` change. Documented as blocked for this `config`-kind change; a follow-up `code` change should add it directly to that component.
- `CohortTimetable` — untouched; its fix is `registry-component-fix` (D01), a separate lane (`lq-defects`).
- Automatically excluding holiday windows from any existing attendance/urentelling calculation — `holidays`/`studyDays` are data only this round; wiring a calculation to read them is a separate change.

## Approach
Two additive schema properties and two new declarative index pages reusing an existing, already-precedented manifest mechanism (`config.filter` with `@`-token resolution, used today by `LessonIndex`/`Submissions`/etc. with `@route.*` tokens; the same `resolveFilterMap` path also resolves `@today`/`@today+Nd`). Declarative only (ADR-031) — no PHP, no new Vue component.

## New Dependencies
None. Depends on `registry-component-fix` (D01, lane `lq-defects`) only for `CohortTimetable`, which this change does not touch.

## Impact
- `lib/Settings/learniq_register.json`: two additive properties on `ReportPeriod`; register version bump.
- `src/manifest.d/learning.json`: two new pages + two new menu entries.
- `tests/Unit/Settings/SchoolYearShapeRegisterTest.php`: new.

## Cross-Project Dependencies
None.

## Rollback Strategy
Revert the register-JSON and manifest-JSON diffs; additive/declarative, no migration to unwind.

## Open Questions
None.
