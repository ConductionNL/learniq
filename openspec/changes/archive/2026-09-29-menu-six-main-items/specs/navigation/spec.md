# navigation Specification

**Status**: in-progress
**Scope**: scholiq
**OpenSpec changes**:
- manifest-fragment-split
- menu-six-main-items

## Purpose

Governs how Scholiq's effective navigation manifest (top-level menu, sub-menus, and the `pages[]` route table) is assembled. This delta adds the top-level information-architecture target — six named destinations plus the two groups pending their own extraction — on top of the fragment-boundary rules `manifest-fragment-split` established.

## ADDED Requirements

### Requirement: The Top-Level Nav Presents Six Named Destinations Plus Two Pending Extractions

Scholiq's main navigation SHALL present exactly the following top-level destinations after this change: `Dashboard`, `Learning`, `People`, `Progress`, `Compliance`, `My learning`, `Data exchange`, `Payments`. No other top-level main-nav item SHALL exist (footer and settings-foldout items are governed separately, unchanged by this requirement). `Data exchange` and `Payments` remain only because their extraction is owned by other changes (`openconnector-flow-migration` and a future pipelinq change respectively) — a future change that completes both extractions SHALL reduce this list to six without requiring a `MODIFIED` to this requirement's structure, only to the enumerated list.

#### Scenario: Exactly eight top-level main-nav items post-change

- GIVEN the effective manifest computed by `buildManifest()` after this change
- WHEN the top-level `menu[]` entries with `section` unset (i.e. not footer, not settings) are counted
- THEN there are exactly 8: `Dashboard`, `Learning`, `People`, `Progress`, `Compliance`, `My learning`, `Data exchange`, `Payments`

#### Scenario: GroupInsight no longer exists

- GIVEN the effective manifest computed by `buildManifest()` after this change
- WHEN the menu tree is searched for an id `GroupInsight`
- THEN it is absent — its 8 children have all been relocated (Dashboard leaves retired, Compliance-flavoured leaves moved under the new `Compliance` group), and `buildManifest`'s existing empty-shell pruning drops the now-childless node

### Requirement: The Dashboard Entry Resolves by Role Instead of Three Separate Rows

The top-level nav SHALL present exactly one `Dashboard` entry, routed to the existing role-aware `Dashboard` page (route `/`, component `ScholiqDashboards`). The three previously-separate role-gated rows (`DashboardAdmin`/"Administration", `DashboardTeacher`/"Teaching", `DashboardStudent`/"My learning") SHALL NOT appear in the nav; their pages SHALL remain routable at their existing routes (`/dashboards/admin`, `/dashboards/teaching`, `/dashboards/my-learning`) for deep links and e2e targets.

#### Scenario: One Dashboard entry replaces three role rows

- GIVEN an admin, a teacher, and a learner, each viewing the top-level nav
- WHEN they look for a dashboard entry
- THEN each sees exactly one `Dashboard` item (not three, not zero), landing on the shared role-aware `ScholiqDashboards` component

#### Scenario: Retired dashboard leaves stay deep-linkable

- GIVEN a bookmark to `/dashboards/admin`
- WHEN it is opened directly (not via nav click-through)
- THEN the page renders normally — the route was never removed from `pages[]`, only its nav leaf

### Requirement: Groups-With-Children Preserve Their Identity When Folded Into a New Parent

When a source top-level group that itself has `children[]` (e.g. `GroupEngagement`, `GroupAdmissions`) is folded into one of the six named destinations, its own label and identity SHALL be preserved as a nested, labeled sub-group of the new parent — never flattened into an undifferentiated sibling list of its former children. This is achieved by declaring the desired nested structure directly in the owning `manifest.d/*.json` fragment(s) rather than via `menu-layout.json#relocations` (which dissolves a relocated group by design — see design.md for the source-verified mechanism).

#### Scenario: Engagement survives as a labeled sub-group under Progress

- GIVEN the effective manifest after this change
- WHEN the `GroupProgress` node's children are inspected
- THEN `GroupEngagement` (with its own label "Engagement" and its own 6 children `Leaderboard`/`Point rules`/`Levels`/`Leaderboards (config)`/`Point awards`/`Learner engagement`) appears as one nested child — not as 6 ungrouped siblings mixed with the other five folded groups' children

#### Scenario: A flattened list is never produced for a many-child fold

- GIVEN any of the seven groups folded into `GroupProgress` (Engagement, Course evaluation, Competencies, Progress & analytics, Portfolios, Study progress, BPV)
- WHEN the effective `GroupProgress.children` array is inspected
- THEN it has exactly 7 entries (one per folded group), not the ~28 that a flattening merge would produce

### Requirement: No Page or Route Is Dropped by the Six-Group Collapse

Per ADR-044 §5, this change SHALL NOT remove any `pages[]` entry or make any previously-routable route unreachable. Every one of the 277 pages present before this change SHALL still resolve after it, whether or not a menu leaf still points at it directly.

#### Scenario: Full page-id set is unchanged

- GIVEN the effective `pages[]` array before this change
- AND the effective `pages[]` array after this change
- WHEN the two sets of page ids are compared
- THEN they are identical — same 277 ids, same routes, same components
- @e2e exclude Build-time verification script — see test-plan.md

#### Scenario: Every pre-change deep link still resolves

- GIVEN the existing Gate-19 e2e route table (`tests/e2e/pages.spec.ts`)
- WHEN it is run unmodified against the post-change build
- THEN every route resolves with no 404 and no missing-component error

## Notes

- This delta does not resolve the tension recorded in the proposal's Open Questions: nesting Engagement/Course evaluation/etc. as sub-groups under `GroupProgress` (Requirement 3 above) technically satisfies "no flattening" but produces a 3-level-deep nav tree (`Progress` → `Engagement` → leaf), which is the exact anti-pattern ADR-044 was written to eliminate via cards-collapse. A card-grid landing page (ADR-044 §4's literal remedy) was not used here because no built-in nc-vue component renders a grid of arbitrary navigation links — `CnCardGrid`/`CnWidgetCardGrid`/`CnObjectCard` are all schema/`OpenRegister`-object-driven, and building a bespoke Vue component would make this a `kind: code` change, which was out of scope for this change's assigned kind. See design.md "Decision 1" and the proposal's top Open Question.
