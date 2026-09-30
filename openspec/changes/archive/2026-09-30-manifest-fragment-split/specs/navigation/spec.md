# navigation Specification

**Status**: in-progress
**Scope**: scholiq
**OpenSpec changes**:
- manifest-fragment-split

## Purpose

Governs how Scholiq's effective navigation manifest (top-level menu, sub-menus, and the `pages[]` route table) is assembled from source files, per the fleet-wide ADR-037 (modular manifest fragments) and ADR-044 (menu architecture: shared `buildManifest` pipeline, settings foldout, cards-collapse). This is a new capability spec — no prior spec file existed for navigation assembly even though earlier changes (`adopt-shared-menu-pipeline`, `nav-restructure-dashboards`, `relocate-dataexchange-remove-assistant`) already modified this area of the code; this spec starts from the measured current state and captures it going forward.

## ADDED Requirements

### Requirement: Manifest Content Lives in Boundary-Scoped Fragments, Not the Monolith

`src/manifest.json` MUST carry only `$schema`, `version`, `dependencies`, `observability`, `deepLinks`, and menu/page entries that do not belong to one of the fourteen named `src/manifest.d/*.json` fragment boundaries (`dashboard`, `learning`, `people`, `progress`, `compliance`, `my-learning`, `work-placement`, `guardian-meetings`, `admissions`, `pupil-record`, `assessment-board`, `progress-decisions`, `data-exchange`, `payments`). Every top-level `menu[]` entry that belongs to one of the fourteen boundaries, together with the full `pages[]` entries it (or its children) reference, MUST live in exactly one fragment file — a single node's `children[]` array MUST NOT be split across two fragment files, because `buildManifest`'s fragment merge order depends on `require.context`'s sorted filenames and a cross-file split of one node's children risks silently reordering the rendered menu.

#### Scenario: A target-group fragment owns a full top-level subtree

- GIVEN the `learning.json` fragment
- WHEN it declares the `GroupLearning` menu entry
- THEN it MUST include `GroupLearning`'s complete `children[]` array (all leaf entries, in their original order) in that single file, and no other fragment or the base manifest MAY also declare a `children` array for `GroupLearning`

#### Scenario: A leaving-app module is isolated to one file

- GIVEN the `data-exchange.json` fragment holding the `GroupDataExchange` menu entry and its associated pages
- WHEN a future change deletes Scholiq's in-app data-exchange surface (per `openconnector-flow-migration`)
- THEN deleting `src/manifest.d/data-exchange.json` alone MUST remove the group's menu entry and all its pages from the effective manifest, with no residual edits required in `src/manifest.json`, `src/menu-layout.json`, or any other fragment

### Requirement: Splitting the Manifest Into Fragments Is a No-Behaviour-Change Refactor

The effective manifest produced by `buildManifest(base, fragments, menuLayout)` after the fourteen-fragment split MUST be deep-equal to the effective manifest produced by the same function before the split — same top-level menu ids in the same order, same `children[]` order at every level, same `pages[]` entries (id, route, type, component, config) with no additions, removals, or reorderings. This MUST be verified by an actual computed diff of the two merged manifests, not by manual inspection or "the app still renders."

#### Scenario: Pre/post split diff is empty

- GIVEN the effective manifest computed from the pre-split tree (monolithic `manifest.json` + the 2 legacy fragments)
- AND the effective manifest computed from the post-split tree (skeleton `manifest.json` + the 14 new fragments)
- WHEN the two are deep-equal-compared (menu tree structure and order, full pages array)
- THEN the diff MUST be empty
- @e2e exclude Build-time/CI verification script, not a browser-observable behaviour — see test-plan.md

#### Scenario: Live nav is visually unchanged

- GIVEN an admin user viewing the Scholiq nav before this change ships
- AND the same admin user viewing the Scholiq nav after this change ships
- WHEN comparing the rendered top-level groups, their order, and each group's children
- THEN the two views MUST be identical — no group, leaf, or route appears, disappears, or moves

## Non-Functional Requirements

- **Performance:** No regression — `require.context` already globs `manifest.d/`; adding 12 more small JSON files to the existing glob has no measurable webpack build-time or runtime-merge cost difference.
- **Accessibility:** N/A — no rendered surface changes.
- **Internationalization:** N/A — no new user-facing strings; existing labels move file but keep their existing `l10n` keys unchanged.

## Acceptance Criteria

- `src/manifest.json` contains no `pages[]` or `menu[]` entries belonging to any of the fourteen named boundaries.
- `src/manifest.d/` contains exactly the fourteen new fragment files (the two legacy fragments are consolidated into `learning.json` and `people.json`, not left alongside them).
- A computed diff of `buildManifest()` output pre- vs. post-split is empty.
- `src/main.js` and `src/menu-layout.json` are byte-identical to their pre-change state.

## Notes

- This spec intentionally does not cover *what* the top-level menu looks like after the six-group collapse — that behavioural change is `menu-six-main-items`, which will add `MODIFIED Requirements` here once it lands.
- `GroupInsight` (the legacy group still holding `Compliance`, three Accessibility leaves, and the AI-processing-disclosure leaf as of this measurement) is assigned wholesale to the `dashboard.json` fragment rather than being pre-split toward its eventual Dashboard/Compliance destinations, to avoid the children-array-split hazard described in REQ-001. See design.md "GroupInsight handling."
- Four utility menu singles (`Documentation`, `FeaturesRoadmapMenu`, `XapiStatementsMenu`, `Rollover`) do not belong to any of the fourteen boundaries and remain in the skeleton `manifest.json`; see the proposal's Open Questions.
