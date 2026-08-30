# Design: menu-six-main-items

## Architecture Overview

Same pipeline as `manifest-fragment-split`: `buildManifest(base, fragments, menuLayout)`. This change edits `menu-layout.json` (relocations/removals) for flat-leaf moves, and edits the *content* of several `manifest.d/*.json` fragments (created by `manifest-fragment-split`) to directly author nested structure for group-with-children folds. No fragment *file* is added or removed; no `pages[]` entry is added, removed, or re-routed.

## Goals / Non-Goals

**Goals**: land Dashboard, Learning, People, My learning at their target shape; land Progress and Compliance as *something* usable within the `kind: config` constraint; preserve every existing page id and route; dissolve `GroupInsight`.

**Non-Goals**: a pixel-perfect card-grid UI for Progress/Compliance (explicitly deferred, see Decision 1); sector-profile visibility toggles; extracting Data exchange or Payments; building any content Compliance/My learning are eventually meant to carry but don't yet have pages for.

## Decisions

### Decision 1: Progress and Compliance get a real card-grid landing page

**This decision was reversed on 2026-08-20, and the reversal is the point of this note.**

The original version of this section nested each source group under the new
parent, producing a 3-level tree (`Progress` → `Engagement` → leaf) — which it
correctly identified as *exactly* the anti-pattern ADR-044's own Context section
exists to eliminate. It accepted that cost for one stated reason:

> No built-in component renders a grid of arbitrary navigation links.

**That reason is no longer true.** `@conduction/nextcloud-vue` **2.8.0**, released
and published to npm, ships `CnNavCardGrid` and registers it as the built-in
widgetKey `nav-card-grid` in `src/components/CnWidgetGrid/builtInWidgets.js`,
beside the existing `'card-grid'`. It was built precisely because this gap
blocked ADR-044 §4 for ten-plus apps (ConductionNL/nextcloud-vue#701).

Verified in the installed package, not assumed:
- `node_modules/@conduction/nextcloud-vue/dist` contains 216 occurrences of
  `nav-card-grid`, including the compiled manifest validator.
- The component renders each `entries[]` item as a native `<router-link>`
  (resolvable `route`), `<a>` (`href`), or a disabled and visibly-flagged
  `<div>` for an unresolvable route — ADR-044 §5 forbids losing a reachable
  function silently.
- It performs NO data fetching; `count: "auto"` resolves from `cnMenuCounts`.
- Entries carry `label`, `description?`, `icon?`, `route?`/`href?`, `count?`,
  `order?` — the vocabulary this change needs and nothing more.

**What this change does**: `Progress` and `Compliance` each become ONE top-level
entry routing to a `type: "dashboard"` page whose single full-width widget is a
`nav-card-grid`, one card per former sub-group. Each card keeps that
sub-group's own label, which is what the nesting workaround was protecting and
what `applyMenuRelocations` destroys (see Decision 2 — that finding stands).

**Consequences of the reversal:**
- Nav depth stays at two, satisfying ADR-044 §4 as written rather than trading
  it away.
- The change stays `kind: config`: a manifest page plus a widget entry. No new
  Vue file ships in this app.
- It gains a hard dependency on `@conduction/nextcloud-vue >= 2.8.0`. The pin
  moves from `^2.3.0` to `^2.8.0` and the lockfile with it. An app resolving an
  older version would render nothing for these two routes, so the version bump
  is part of this change, not a follow-up.
- The follow-up `kind: code` change the original section recommended is no
  longer needed and should not be filed.

### Decision 2: Why flattening (the `menu-layout.json#relocations` default) was rejected, with evidence

`buildManifest.js`'s `applyMenuRelocations` — read directly from `node_modules/@conduction/nextcloud-vue/src/utils/buildManifest.js` — dissolves a relocated group on purpose. Its own docstring: *"A relocated GROUP dissolves: its children merge (by id) into the target group and the now-empty shell is dropped."* The implementation confirms it: when the relocated `node` has a `children` array, only `mergeMenuItems(group.children, node.children)` runs — the node's own `id`/`label`/`icon` are discarded, never added to the target's children.

Measured what this would mean for Progress specifically: its 7 source groups' children include labels like `Levels` (ambiguous — Competency levels or Engagement levels?), `Responses` (survey responses? assessment responses?), `Overview`-shaped names, `Warnings`, `Placements`. Flattened into one ~28-item list with no parent-group heading, several of these lose the context that makes them legible. This is a measured usability regression, not a hypothetical one — confirmed by pulling the actual `children[]` labels for `GroupEngagement`, `GroupCourseEvaluation`, `GroupCompetency`, `GroupStudentAnalytics`, `GroupPortfolio`, `GroupStudyProgress`, `GroupBpv` from the current `manifest.json` before writing this design.

**Alternative considered**: relabel every flattened leaf with a domain prefix (e.g. "Engagement: Levels") to disambiguate a flat list. Rejected: touches ~28 labels (each needing a translation-key update per ADR-007), reads as a worse UI than a nested group either way, and the task's naming brief explicitly restricts label changes to cases with a clear reason plus an added translation key — relabeling everything to compensate for a structural choice isn't that.

### Decision 3: Canonical-fragment-declares-the-parent convention

For each new parent (`GroupPeople` already existing; `GroupProgress`, `GroupCompliance` new), exactly one fragment is the "canonical" declaration carrying `label`/`icon`/`order`/`route` (if any): `people.json`, `progress.json`, `compliance.json` respectively — the same files `manifest-fragment-split` already designated as each target group's primary fragment. Every other fragment that contributes a nested sub-group (`admissions.json`, `pupil-record.json`, `guardian-meetings.json` → `GroupPeople`; `progress-decisions.json`, `work-placement.json` → `GroupProgress`; `assessment-board.json` → `GroupCompliance`) declares the SAME parent id but supplies only its own already-labeled group as one more entry in that id's `children[]` — never redeclaring `label`/`icon`/`order`. `mergeMenuItems`'s existing "first-declared, not-yet-set field wins" semantics (`if (existing[key] === undefined && item[key] !== undefined)`) make the merge order-independent for these scalar fields as long as only one fragment ever sets them, which this convention guarantees.

### Decision 4: The `Compliance` leaf/group name collision

The new top-level `Compliance` group and the pre-existing `Compliance` leaf page (id `Compliance`, route `Compliance`, currently a child of `GroupInsight`) share the literal label "Compliance." Nested, this reads as parent "Compliance" containing a child also labeled "Compliance" — confusing. The `id` and `route` are unchanged (ADR-044 §5 / the task's naming brief forbid touching those); only the **label** is changed, to "Overview," with an added `l10n` key (ADR-007). This is the one relabel this change makes; every other existing label is untouched.

### Decision 5: `GroupBpv` (work placement) joins Progress

The originating brief's explicit "six cards" enumeration for Progress didn't name BPV, but its surrounding prose described Progress as covering "work placements" too, and BPV/work-placement tracks a learner's progress during a practical placement — squarely a "how are they doing" surface like the other six. Folded in as a seventh sub-group under `GroupProgress` via the same Decision 1/3 mechanism. Flagged as a DEFERRED_QUESTION since the brief's enumerated list and its prose disagreed.

## Risks / Trade-offs

- [Risk] Decision 1's 3-level nesting for Progress/Compliance is a real ADR-044 tension, not fully resolved by this change → **Mitigation**: documented exhaustively here and in the proposal; flagged as the top DEFERRED_QUESTION; a follow-up `kind: code` change is the recommended path once scoped.
- [Trade-off] `GroupCompliance`'s "Overview" relabel is a small, deliberate deviation from "no renames" — justified narrowly (name-collision avoidance, translation key added) rather than applied broadly.
- [Risk] `GroupBpv` joining Progress is an inference from ambiguous prose, not an explicit instruction → **Mitigation**: DEFERRED_QUESTION recorded; easy to move in a follow-up if wrong (BPV's fragment file, from `manifest-fragment-split`, is untouched in shape — only which parent id its content nests under would need to change).

## Migration Plan

1. `src/manifest.d/dashboard.json` — add the new `Dashboard` top-level menu entry (route → existing `Dashboard` page).
2. `src/menu-layout.json` — remove the three now-superseded `DashboardAdmin`/`DashboardTeacher`/`DashboardStudent` → `__toplevel__` relocations; add those three ids to `removals`.
3. `src/manifest.d/learning.json` — nest `GroupTimetabling` under `GroupLearning` (in-file restructure, both already colocated per `manifest-fragment-split`).
4. `src/manifest.d/people.json`, `admissions.json`, `pupil-record.json`, `guardian-meetings.json` — restructure per Decision 3.
5. `src/manifest.d/progress.json`, `progress-decisions.json`, `work-placement.json` — new `GroupProgress` parent, restructure per Decision 3.
6. `src/manifest.d/compliance.json`, `assessment-board.json` — new `GroupCompliance` parent, restructure per Decision 3; relabel the `Compliance` leaf to "Overview" (Decision 4) with its new `l10n` key.
7. `src/manifest.d/my-learning.json` unchanged; `src/menu-layout.json` relocations add `MyTimetableMenu`/`MyLearningRecordMenu` → `GroupMyLearning` (or whatever id `my-learning.json` declares — see `manifest-fragment-split`'s file, which currently holds these as flat unparented leaves; this change adds the wrapping parent id there or relocates them under it, whichever the fragment's exact current shape needs — a one-line call left to the implementer since it's mechanical).
8. Verify: full page-id set unchanged (id-set diff), `GroupInsight` absent, exactly 8 top-level main-nav items, `GroupProgress.children.length === 7`, existing Gate-19 e2e suite green unmodified.

**Rollback**: revert the commit; no data/schema/API surface is touched.

## Open Questions

Restated from the proposal for design-doc completeness:

- 3-level nesting vs. deferring Progress/Compliance to a follow-up `kind: code` change — see Decision 1.
- `GroupBpv` under Progress vs. elsewhere — see Decision 5.
- Relabeling the `Compliance` leaf to "Overview" vs. renaming the new group instead — see Decision 4.

## The invariant (ADR-044 §5, restated for this change)

A navigation refactor MUST NOT drop any page route or any reachable function. Every one of the 277 `pages[]` entries present before this change MUST still resolve after it — the `DashboardAdmin`/`DashboardTeacher`/`DashboardStudent` pages in particular, whose only change is losing a menu leaf (via `removals`), MUST remain fully routable at their existing routes. `menu-layout.json#removals` in this change retires exactly those three ids, each justified individually in the proposal (Scope point 1) as a duplicate nav entry superseded by the new unified `Dashboard` item, with the underlying page unchanged. No other id is added to `removals`. Verification: the full pre/post page-id set (id, route, type, component) is diffed and MUST be empty (test-plan.md), and the existing Gate-19 e2e route table MUST pass unmodified against the post-change build.
