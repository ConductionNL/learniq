---
kind: config
---

# Proposal: cohort-group-page-polish

## Summary
`CohortDetail` (the group page) has a large empty band, no notes surface, and no way to see just today's sessions at a glance (round-1 finding 1.11, NICE tier; `../learniq-baseline/group-page-anatomy.md` captured all three against a live "DR Groep 7" cohort). This change adds a `Cohort.notes` free-text field with a dedicated Notes widget, and a "Today's sessions" widget filtered to the current day using the manifest's existing `@today` filter-token grammar. The header's cohort-name gap named in the same baseline capture is already closed by the current `@conduction/nextcloud-vue` (`CnDetailPage`'s `objectDisplayName` already prefers `Cohort.name` over the static "Cohort" label) — verified by reading `CnDetailPage.vue`'s source rather than re-implemented.

## Motivation
`group-page-anatomy.md`'s capture against a live cohort names three concrete gaps: (1) the header shows plain text "Cohort", not the cohort's own name; (2) a roughly 550px empty band between the header and the KPI tiles, reading as a missing card; (3) the roster shows raw learner/course IDs with no notes and no "today" lens, unlike Gibbon's Form Group page (roster + tutor + quick links) or ESIS's "Dashboard Mijn Groep" (administratie, dossiers, groepsplannen, OPP, toetsen in one place). Of the three, (1) is a library behaviour, not a manifest gap: `CnDetailPage.vue`'s `displayTitle` computed property already resolves `objectDisplayName` (checking `obj.name`/`obj.title`/`obj.displayName` in order) ahead of the static `title` prop, and `Cohort.name` already exists and is required — so the header already names the cohort once `resolvedObject` loads, with no manifest change needed. This proposal closes the other two: notes, and a today-scoped session view.

## Affected Projects
- [x] Project: `learniq` — `lib/Settings/learniq_register.json` (`Cohort` gains an additive `notes` property); `src/manifest.d/learning.json` (`CohortDetail` gains a Notes widget and a Today's Sessions widget).

## Scope

### In Scope
- `Cohort.notes` — additive, nullable free-text string.
- A `coh-notes` widget on `CohortDetail`, scoped to just the `notes` field (mirrors the existing `enrol-details2` pattern of a `content.include`-scoped Data widget).
- A `coh-sessions-today` widget on `CohortDetail`: an object-list of this cohort's `Session`s filtered to `startsAt` within today, using the manifest's existing `@today`/`@today+1d` filter-token grammar (no new code — the same grammar already used by stats-block/object-table widgets elsewhere in this register's manifest).
- A register-JSON unit test for `Cohort.notes`.

### Out of Scope
- The header-name fix — already provided by the installed `@conduction/nextcloud-vue` (see Motivation); verified, not re-implemented.
- `CohortTimetable` (the dead `CnTimelineView` custom page) — untouched; its fix is `registry-component-fix` (D01), a separate lane.
- A structured notes history/audit beyond the existing per-object audit-trail sidebar tab CohortDetail already has.

## Approach
One additive schema property and two new widgets on the existing `CohortDetail` page, using declarative filter tokens already supported by the manifest schema. Declarative only (ADR-031) — no PHP, no new Vue component.

## New Dependencies
None.

## Impact
- `lib/Settings/learniq_register.json`: one additive property (`Cohort.notes`); register version bump.
- `src/manifest.d/learning.json`: two new widgets + layout entries on `CohortDetail`.
- `tests/Unit/Settings/CohortGroupPagePolishRegisterTest.php`: new.

## Cross-Project Dependencies
None.

## Rollback Strategy
Revert the register-JSON and manifest-JSON diffs; additive/declarative, no migration to unwind.

## Open Questions
None.
