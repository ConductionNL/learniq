---
kind: code
depends_on: []
---

# Proposal: global-search

## Summary

The People dashboard (`PeopleDashboard.vue`) gets a fast-finder search box over `LearnerProfile` (split
client-side into Learners/Staff by role) and `Cohort`, with results grouped by kind and keyboard-reachable
via `NcListItem`'s router-link. This closes finding G-new-1 — learniq's `usability.md` scored "global search
scope" 0/3 ("The only search box seen anywhere is Nextcloud's own header-level search... No in-app search
across cohorts/learners/report cards was found"), the worst usability gap measured in this round, against
gibbon's header-level Fast Finder (`journeys.md` J4: 2 clicks, ~5s to relocate a pupil from anywhere).

## Motivation

`placement.md` calls this "the single highest usability score gap" and rates it cheap to close (rung 4, one
widget). `change-plan.md`'s `global-search` row scopes it exactly this way: "An index-level fast-finder
widget across LearnerProfile/Cohort/Staff... no new top-level menu entry." learniq has no dedicated `Staff`
schema (grepped `lib/Settings/learniq_register.json`: only `LearnerProfile`/`Cohort` and friends) — staff are
`LearnerProfile` rows whose `roles` array holds a non-learner role, so this change classifies results
client-side rather than adding a schema.

## Affected Projects

- [x] Project: `learniq` — a new `src/utils/globalSearch.js` query builder/classifier, a new
  `GlobalSearchWidget.vue` on `PeopleDashboard`, unit tests on the query builder.

## Scope

### In Scope

- `src/utils/globalSearch.js`: `buildGlobalSearchRequests(term)` (builds the `learner-profile`/`cohort`
  `_search` requests OpenRegister's `ObjectsController` already honours for `searchable: true` schemas),
  `classifyPersonKind(roles)`, `groupGlobalSearchResults(...)`, `personResultLabel(...)`.
- `src/views/widgets/GlobalSearchWidget.vue`: a debounced `NcTextField` + three result groups (Learners,
  Staff, Cohorts), each row an `NcListItem` with a `to` route (native router-link, keyboard-reachable by
  Tab/Enter with no bespoke ARIA code).
- Wiring the widget into `PeopleDashboard.vue`'s existing `CnDashboardPage` widgets/layout as a new full-width
  `custom`-type widget above the KPI row.
- Unit tests: `tests/unit-js/globalSearch.test.mjs` (`node --test`, matching `courseOrder.test.mjs`'s
  convention) covering the query builder and the role classifier.

### Out of Scope

- A Nextcloud header-level "Fast Finder" (gibbon's actual placement) reachable from every page — that is a
  platform-wide Nextcloud unified-search provider integration, a materially larger surface than "a search box
  in the People dashboard" this lane's brief scopes to.
- Searching `report-card`, `dossier-note`, or any schema beyond `learner-profile`/`cohort` — not named in
  G-new-1's `change-plan.md` scope line.
- A new top-level menu entry — explicitly excluded by `change-plan.md`.
- Verifying OpenRegister's `_search` full-text ranking/relevance behaviour — that is the platform's own
  concern (ADR-022); this change only issues the documented `_search` param and renders what comes back.

## Approach

Two pure, directly-unit-testable functions build the per-kind OpenRegister request descriptors and classify/
group the results; the Vue component only wires them to a debounced text input and a router-link list. No
new PHP controller, route, or schema — `learner-profile` and `cohort` are already `searchable: true` and
already served by OpenRegister's generic object API.

## Capabilities

### Modified Capabilities

- `dashboard` — `PeopleDashboard` gains a fast-finder search widget (G-new-1). The pre-existing "People
  domain dashboard" requirement itself is owned by the still-open `nav-restructure-dashboards` change and is
  not edited here; this change adds a new, additive requirement alongside it.

## New Dependencies

None — `_search` is an existing OpenRegister `ObjectsController` query parameter (grepped: used across
`SearchController`, `ObjectsController`, `AuditTrailController` in the `openregister` app); no new npm
package (a hand-rolled `setTimeout` debounce avoids adding a debounce dependency for one call site).

## Impact

- `src/utils/globalSearch.js` (new).
- `src/views/widgets/GlobalSearchWidget.vue` (new).
- `src/views/PeopleDashboard.vue` — one new widget + one new layout entry; existing KPI/manage-list widgets
  shift down two grid rows.
- `tests/unit-js/globalSearch.test.mjs` (new).

## Cross-Project Dependencies

None.

## Risks

### Risk 1: OpenRegister's `_search` behaviour for `LearnerProfile`/`Cohort` was not verified against a live instance in this change

**Severity:** Medium — **Mitigation:** `_search` is a documented, already-used OpenRegister query parameter
(not invented by this change), and both schemas already declare `x-openregister.searchable: true`. If a live
verification pass (deferred — see Open Questions) finds `_search` behaves differently than assumed (e.g.
prefix-only, not substring), the fix is confined to `fetchOne()`'s param name/shape in
`GlobalSearchWidget.vue`, not to the tested query-builder contract itself.

### Risk 2: Classifying "Staff" purely client-side from `roles` misses a staff member with no `roles` set

**Severity:** Low — **Mitigation:** documented in `classifyPersonKind`'s own description and covered by a
named test case (`no roles at all defaults to learner, not staff`); a school onboarding a new staff record
without setting `roles` yet is treated as a data-entry gap the school itself would notice and fix, not a
silent search failure — the record still shows up in the "Learners" group rather than not at all.

## Rollback Strategy

Revert the commit(s). No schema, migration, or route is added; the widget is additive to `PeopleDashboard`,
so removing it restores the page's prior layout exactly.

## Open Questions

- A live browser verification pass against a running instance (confirming `_search` actually filters by
  substring on `givenName`/`familyName`/`name`) is deferred in this pass — no local Nextcloud instance was
  exercised for this change; the query-builder contract is unit-tested, and the fetch call is isolated in one
  method (`fetchOne`) if the live param shape needs adjusting.
