# Design: school-year-shape

## Context
`ReportPeriod` has `startDate`/`endDate` for the attendance-summarisation window but no way to carve out holidays or mark study days within it. `Sessions` has only a flat index; the nearest week/day attempt, `CohortTimetable`, mounts a dead `CnTimelineView` (D01, a separate lane's fix, explicitly out of scope here). `TimetableConflictQueue` has no per-teacher filter, but it is a real, registered custom Vue page, not a manifest-declarative one.

## Goals / Non-Goals
- **Goal**: give `ReportPeriod` a place to record holidays and study days.
- **Goal**: give `Sessions` a today/week-scoped view without a new custom component.
- **Non-goal**: wiring holidays/study days into any existing attendance calculation — data only this round.
- **Non-goal**: the `TimetableConflictQueue` per-teacher filter — genuinely a `code` change; see Decision 3.

## Decisions

### Decision 1: `holidays`/`studyDays` are plain arrays on ReportPeriod, not new schemas
Both are small, bounded, period-scoped facts (a school year has a handful of holiday blocks and study days), matching the proportional size of two NICE-tier rows bundled into one change. A dedicated `Holiday`/`StudyDay` schema would be over-scoped for data that never needs its own index/detail page or cross-object reference.

### Decision 2: The today/week Sessions views are index pages with a token-resolved `config.filter`, not a calendar component
Traced the actual code path before building anything: `CnIndexPage`'s self-fetch mode calls `useSelfFetchList`, whose `fixedFilters` getter runs `resolveFilterMap(props.filter, params, ctx)` (`src/utils/routeFilters.js`), which calls `resolveFilterTokens` — the same `@today`/`@today±Nd`/`@monthStart` grammar object-list/stats-block widgets already use. This manifest already has five precedented `type: index` pages using `config.filter` with `@route.*` tokens (`LessonIndex`, `Submissions`, `PeerReviews`, `SelfAssessments`, `PeerFeedbackSummaries`); this change is the same mechanism with a different token family, not a new capability.

### Decision 3: The TimetableConflictQueue per-teacher filter is deferred, not built
`src/views/TimetableConflictQueue.vue` is confirmed present and registered in `src/registry.js` (unlike `CohortTimetable`'s dead `CnTimelineView`) — it is real code, and its own `_note` calls it out as "the one genuine new routed custom view" in its originating change. A filter control there is a Vue-component change, which would make this proposal `mixed` under ADR-032 if bundled in. Splitting it out as a follow-up `code` change keeps this change's `kind: config` honest rather than quietly smuggling a `.vue` edit into a config-labelled PR.

## Declarative-vs-imperative decision (ADR-031)
No lifecycle, aggregation, calculation, or notification behaviour. Two additive array properties and two index pages with token-resolved filters, JSON-only.

## Seed Data (ADR-001)
`ReportPeriod` seed: `holidays: [{ name: "Herfstvakantie", startDate: "2025-10-20", endDate: "2025-10-24" }]`, `studyDays: [{ date: "2025-11-14", description: "Studiedag team" }]`.

## Risks / Trade-offs
[Risk] A coordinator managing one teacher's conflicts still sees the whole `TimetableConflictQueue` this round → Mitigation: named explicitly as deferred (Decision 3), not silently dropped; tracked as a follow-up `code` change.

## Migration Plan
Declarative only. Revert the JSON diffs to roll back.

## Open Questions
None.
