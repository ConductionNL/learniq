# Design: cohort-group-page-polish

## Context
`group-page-anatomy.md` captured three gaps against a live cohort detail page: (1) a static "Cohort" header instead of the cohort's name, (2) a large empty band with no notes, (3) a roster with no "today" lens. Gibbon's Form Group page (roster + tutor + quick links) and ESIS's "Dashboard Mijn Groep" (administratie, dossiers, groepsplannen, OPP, toetsen in one place) both put more at-a-glance context on their equivalent page than learniq's `CohortDetail` does today.

## Discovery: the header gap is already closed upstream
Before building anything for (1), `node_modules/@conduction/nextcloud-vue/src/components/CnDetailPage/CnDetailPage.vue` was read directly. Its `objectDisplayName` computed property (lines ~1900-1911) checks `obj['@self'].name`, `obj['@self'].title`, `obj.name`, `obj.title`, `obj.displayName` in order and returns the first non-empty, non-id value; `displayTitle` (the header `<h2>` text) returns `objectDisplayName || resolvedTitle` (the static manifest `title` prop is only the fallback). `Cohort.name` is already a required property. So the header already resolves to the cohort's name once the object loads — the baseline capture's gap was a library-version-in-production issue (the installed `node_modules` in this lane's clone pins `2.37.0` while `package.json` asks for `^2.56.0`; a stale local install, not a real repo issue) rather than a manifest gap. No manifest change closes (1); it is verified, not re-implemented, and called out explicitly here so the gap is not silently dropped from tracking.

## Goals / Non-Goals
- **Goal**: give `CohortDetail` a notes surface and a today-scoped session view.
- **Goal**: verify (not silently drop) the header-name gap.
- **Non-goal**: `CohortTimetable` (D01's territory, untouched).
- **Non-goal**: a rich-text or versioned notes history — a plain string, same shape as `Enrolment.reason`.

## Decisions

### Decision 1: `Cohort.notes` is a plain string, not a new schema
Gibbon's and ESIS's group pages both show free-text observations inline, not as a separate append-only log (that shape already exists elsewhere in this register for `DossierNote`, which is pupil-scoped, not group-scoped). A single nullable string on `Cohort` matches the proportional size of this NICE-tier row.

### Decision 2: "Today" uses the existing `@today`/`@today+1d` filter-token grammar
The manifest schema already documents `@today`, `@now`, `@today±Nd` as resolved filter values (`resolveFilterTokens`, used today by stats-block/object-table widgets and object-list `filter` maps elsewhere in this register's own manifest — e.g. `Enrolment.dueReminder`-style scheduled filters). `startsAt: { gte: "@today", lt: "@today+1d" }` on a new object-list widget is declarative and needs no new code, unlike a bespoke day/week calendar component (which would also risk repeating D01's dark-page defect if the component isn't registered in `src/registry.js`).

## Declarative-vs-imperative decision (ADR-031)
No lifecycle, aggregation, calculation, or notification behaviour. One additive property and two widgets, JSON-only.

## Seed Data (ADR-001)
This change adds its own `Cohort` seed ("Groep 7") since this branch is cut from `origin/development`, independent of this lane's other stacked/parallel changes: `notes: "Combinatiegroep, extra aandacht voor rekenen."`. No `Session` seed exists in this register to demonstrate the today-filter against; the widget's filter is verified by shape (the declared `filter` object), not by a live-data scenario, matching this register's existing precedent for calculation/notification shape-only tests (`GroepsplanRegisterTest`'s documented scope note).

## Risks / Trade-offs
[Risk] The header-name fix depends on the consuming app actually running `@conduction/nextcloud-vue` >= the version that carries `objectDisplayName` → Mitigation: confirmed present in the currently locked `2.37.0` already (the feature is not new); `package.json`'s `^2.56.0` floor is higher still, so this holds in the real dependency range, not only in this lane's possibly-stale local install.

## Migration Plan
Declarative only. Revert the JSON diffs to roll back.

## Open Questions
None.
