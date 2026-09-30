# Design: manifest-fragment-split

## Architecture Overview

Scholiq's frontend already assembles its navigation through the shared pipeline:

```
src/manifest.json (base)  ─┐
src/manifest.d/*.json      ├─▶ buildManifest(base, fragments, menuLayout) ─▶ effective manifest
src/menu-layout.json       ─┘        (src/main.js, unchanged by this change)
```

`buildManifest` (from `@conduction/nextcloud-vue`, `node_modules/@conduction/nextcloud-vue/src/utils/buildManifest.js`) does three things in order: (1) merge every fragment's `pages`/`menu` onto the base, id-keyed — `mergePages` replaces a page wholesale by id, `mergeMenuItems` unions `children[]` recursively by id and fills in any scalar field the earlier declaration left `undefined`; (2) apply `menuLayout.relocations` (source id → target group id, `"__toplevel__"` lifts to top level); (3) apply `menuLayout.removals` then `menuLayout.settingsSection`. None of this changes. This change only moves *where* the pre-merge content is declared — from one 14,663-line `manifest.json` to fourteen small files — while the merge algorithm reassembles byte-identical output.

Measured current state (re-verified against the live tree, not assumed from an earlier snapshot — see "Correction to the brief" below):

- `src/manifest.json`: 24 top-level `menu[]` entries, 275 `pages[]` entries.
- `src/manifest.d/`: 2 fragments (`learning-dashboard.json` adds the `LearningDashboard` page + fills `GroupLearning.route`; `people-dashboard.json` does the same for `GroupPeople`/`PeopleDashboard`).
- `src/menu-layout.json`: already carries the `nav-restructure-dashboards` + `relocate-dataexchange-remove-assistant` relocations (`DashboardAdmin`/`DashboardTeacher`/`DashboardStudent`/`Compliance` → `__toplevel__`), `removals: []`, `settingsSection: [XapiStatementsMenu, Rollover]`. **This change does not touch this file.**
- Effective (post-`buildManifest`) manifest today: 277 pages (275 base + 2 fragment-added dashboard pages), 24 main-nav top-level items + 2 footer + 2 settings-foldout = 28 total menu nodes.

### Correction to the brief

The task brief's "23 top-level groups, 89 leaves" figure does not match a direct computation of `buildManifest()` against the current tree (24 main-nav top-level items, 91 leaves under them). The most consequential discrepancy: `GroupInsight` is **not** fully dissolved despite `nav-restructure-dashboards`' proposal claiming "the emptied group shell is dropped." `GroupInsight` in the base manifest has 8 children — `DashboardAdmin`, `DashboardTeacher`, `DashboardStudent`, `Compliance` (all four relocated to top level by the current `menu-layout.json`) plus `Accessibility`, `AccessibilityLimitationsMenu`, `AiProcessingDisclosureMenu`, `AccessibilityFeedbacksMenu` (not relocated — `applyMenuRelocations` only drops a group shell when it ends up with zero children, and these four survive). `GroupInsight` therefore still renders today, non-empty, as its own top-level item. This is a pre-existing gap in `nav-restructure-dashboards`, not something this change introduces or is responsible for fixing — `menu-six-main-items` (the next change) is where Compliance's real destination gets decided.

## Goals / Non-Goals

**Goals**: (1) every one of the fourteen named boundaries becomes exactly one `manifest.d/*.json` file; (2) `manifest.json` shrinks to a skeleton; (3) the effective manifest is provably unchanged.

**Non-Goals**: reshuffling what's in the top-level menu (that's `menu-six-main-items`); resolving the `GroupInsight` non-dissolution gap; adding sector-toggle `visibleIf` logic to the six module fragments (they get a file boundary now, gating logic is future work); touching `menu-layout.json` or `main.js` at all.

## Decisions

### Decision 1: One node's full subtree per file — never split `children[]` across fragments

`mergeMenuItems` unions `children[]` arrays by pushing each fragment's children in `require.context`'s sorted-filename processing order. If `GroupLearning`'s 17 children were declared 9 in `learning.json` and 8 in (say) a hypothetical `learning-extra.json`, the merged order would be "whichever file's fragment sorts first, in full, then the other file's, in full" — not the original interleaved order. Since two of the six target groups (`Learning`, `People`) already render as navigable-and-collapsible domain dashboards (children visibly ordered in the nav), an order change is a real, user-visible regression, not a cosmetic one. The rule adopted: **a top-level menu id's entire subtree — the node plus its full, unmodified `children[]` — is assigned to exactly one fragment file.** No fragment re-declares a `children` array for an id another fragment already owns.

**Alternative considered**: pre-split `GroupInsight`'s 8 children now, sending the 4 Dashboard-flavoured ones toward `dashboard.json` and the 4 Compliance-flavoured ones toward `compliance.json`, on the theory that `menu-six-main-items` will need that split anyway. Rejected for this change: `menu-six-main-items` performs its regrouping via `menu-layout.json` relocations, which operate on the *merged* menu regardless of source file — pre-splitting the fragment gains nothing for that change and adds the ordering hazard this decision exists to avoid. Fragment boundaries and menu-layout relocations are deliberately independent axes; conflating them here would make the "no behaviour change" verification harder to trust, not easier.

### Decision 2: GroupInsight goes to `dashboard.json`, whole

Given Decision 1, `GroupInsight` (all 8 children, unmodified, in original order) is assigned to one fragment file. `dashboard.json` is chosen over `compliance.json` because 3 of its four *already-relocated* children (`DashboardAdmin`/`DashboardTeacher`/`DashboardStudent`) are Dashboard-flavoured and the base id itself is the ancestor of the future unified `Dashboard` top-level item; `compliance.json` becomes a thinner file for now (see Decision 3) but that's a labeling cost, not a behavioural one — `menu-layout.json` relocations don't care which file a node's id came from.

**Alternative considered**: put it in `compliance.json` instead (5 of 8 children — `Compliance`, 3 Accessibility leaves, AI-disclosure — are Compliance-flavoured, a numeric majority). Reasonable either way; recorded as a DEFERRED_QUESTION in the final report since it's a naming call with no behavioural consequence.

### Decision 3: The fourteen-boundary → current-top-level-id mapping

| Fragment file | Base top-level `menu[]` id(s) assigned | Leaves today |
|---|---|---|
| `dashboard.json` | `GroupInsight` (all 8 children unchanged, see Decision 2) | 8 (incl. 4 already relocated) |
| `learning.json` | `GroupLearning`, `GroupTimetabling` (scheduling/rooms/sessions read as Learning per the next change's target grouping; kept together here so that change is a pure `menu-layout.json` edit) | 17 + 3 = 20 |
| `people.json` | `GroupPeople` | 4 |
| `progress.json` | `GroupEngagement`, `GroupCourseEvaluation`, `GroupCompetency`, `GroupStudentAnalytics`, `GroupPortfolio` | 6+4+4+5+5 = 24 |
| `compliance.json` | `ExternalTraining` | 1 |
| `my-learning.json` | `MyTimetableMenu`, `MyLearningRecordMenu` | 1+1 = 2 |
| `work-placement.json` | `GroupBpv` | 5 |
| `guardian-meetings.json` | `GroupConferences` | 5 |
| `admissions.json` | `GroupAdmissions` | 3 |
| `pupil-record.json` | `GroupPupilDossier` | 3 |
| `assessment-board.json` | `GroupExamBoard` | 3 |
| `progress-decisions.json` | `GroupStudyProgress` | 4 |
| `data-exchange.json` | `GroupDataExchange` | 4 |
| `payments.json` | `GroupPayments` | 5 |

Remaining in the skeleton `src/manifest.json` (no boundary owns them): `Documentation`, `FeaturesRoadmapMenu` (footer), `XapiStatementsMenu`, `Rollover` (settings foldout) — 4 items, all singles with no children, all already routed to footer/settings by the existing `menu-layout.json`.

**Why `GroupTimetabling` joins `learning.json` rather than getting its own file**: it isn't in the task's fourteen named boundaries (a fifteenth "timetabling" fragment isn't requested), and `menu-six-main-items`'s own description of the target Learning group explicitly lists "sessions, rooms, scheduling" as belonging to Learning. Placing it with `GroupLearning` now means the next change's Learning-group consolidation needs zero fragment work, only a `menu-layout.json` relocation for the other Learning-bound groups that don't yet have a fragment home matching their eventual parent (none do, by design — see Decision 1).

**Why `compliance.json` is nearly empty today**: `Compliance`, the three Accessibility leaves, and `AiProcessingDisclosureMenu` are still physically nested inside `GroupInsight` (Decision 2), and `GroupExamBoard` (a Compliance-bound card per the next change) has its own `assessment-board.json` file per the task's explicit module list. This is intentional, not a mistake — it is the direct, honest consequence of the "no behaviour change" and "no cross-fragment children split" rules applied to a group that hasn't been reshuffled yet.

### Decision 4: Consolidate, don't duplicate, the two existing fragments

`learning-dashboard.json` and `people-dashboard.json` already declare `{id: "GroupLearning", route: "LearningDashboard"}` / `{id: "GroupPeople", route: "PeopleDashboard"}` plus their respective dashboard pages. Rather than leaving those two files alongside new `learning.json`/`people.json` files (which would also need to declare `GroupLearning`/`GroupPeople` — two files both touching the same id, relying on `mergeMenuItems`' merge-not-overwrite semantics to combine them correctly), this change **renames and merges**: the dashboard route + dashboard page move into `learning.json` / `people.json` alongside that group's full children array. One file per id, per Decision 1.

## Risks / Trade-offs

- [Risk] A future contributor adds a fifteenth fragment file for a boundary not in this list (e.g. splits `progress.json` further) without re-running the deep-equal verification → **Mitigation**: the verification script (test-plan.md) is cheap enough to run in CI on any `manifest.d/` change, not just this one; recommend wiring it as a standing check in a follow-up, out of scope here.
- [Risk] `GroupTimetabling` folded into `learning.json` reads as scope creep beyond the fourteen named boundaries → **Mitigation**: documented explicitly above with the reasoning; flagged as a DEFERRED_QUESTION in the final report so it can be reverted to its own file if the reviewer disagrees.
- [Trade-off] `compliance.json` ships nearly empty. Accepted: forcing content into it now would mean violating Decision 1 (splitting `GroupInsight`'s children) purely for cosmetic fullness.

## Migration Plan

1. Create the 14 new fragment files with the content mapped in Decision 3, copying each assigned top-level node's subtree verbatim (byte-for-byte) from the current `manifest.json`.
2. Fold `learning-dashboard.json` / `people-dashboard.json` content into `learning.json` / `people.json`, then delete the two old files.
3. Strip the now-relocated content from `manifest.json`, leaving the skeleton (5 metadata keys + 4 utility singles + their pages).
4. Run the deep-equal verification (test-plan.md) against a pre-split git ref of the tree.
5. Manual spot-check in the running app (localhost:8080) — nav renders identically.

**Rollback**: revert the commit; no data/schema/API surface is touched, so this is a pure code revert.

## Open Questions

- Should `GroupTimetabling` get its own `timetabling.json` fragment instead of folding into `learning.json`? Provisionally folded in; see DEFERRED_QUESTIONS in the final report.
- Should the four leftover utility singles get a `shell.json` fragment for consistency, or stay in the skeleton `manifest.json` permanently? Provisionally left in `manifest.json`.
- `GroupInsight` → `dashboard.json` vs. `compliance.json`: a labeling call with no behavioural stakes; provisionally `dashboard.json` (Decision 2).

## The invariant (ADR-044 §5, restated for this change)

A navigation refactor MUST NOT drop any page route or any reachable function. This change moves *where* every page and menu node is declared; it MUST NOT change *which* ids exist, *what* each id's fields are, or the *order* of any `children[]` array. `menu-layout.json`'s `removals` key may retire only a duplicate navigation entry whose page stays reachable another way — this change makes zero `removals` edits, so the question doesn't arise here, but the rule is restated because `menu-six-main-items` (which does edit `removals`) inherits this fragment layout as its starting point, and its own design.md carries the same invariant for the edits it actually makes. Verification for this change is: **every one of the 277 effective `pages[]` entries and all 24 top-level menu ids (with their full children trees, in order) are identical before and after — proven by a computed diff, not by "the app still looks right."**
