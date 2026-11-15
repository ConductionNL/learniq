# Test Plan: menu-six-main-items

## Test Cases

### TC-1: Exactly eight top-level main-nav items
- **spec_ref**: `openspec/changes/menu-six-main-items/specs/navigation/spec.md#requirement-the-top-level-nav-presents-six-named-destinations-plus-two-pending-extractions`
- **type**: functional
- **persona**: n/a (admin smoke check)
- **preconditions**: Scholiq deployed on localhost:8080 with this change applied
- **steps**: log in as admin, open the left nav, count the top-level main-nav items (excluding footer and the settings-foldout gear)
- **expected result**: exactly 8 — `Dashboard`, `Learning`, `People`, `Progress`, `Compliance`, `My learning`, `Data exchange`, `Payments`; `Insight` is absent
- **test command**: `/test-functional`

### TC-2: One Dashboard entry per role, old rows retired but still deep-linkable
- **spec_ref**: `openspec/changes/menu-six-main-items/specs/navigation/spec.md#requirement-the-dashboard-entry-resolves-by-role-instead-of-three-separate-rows`
- **type**: persona
- **persona**: three passes — an admin, a teacher, and a learner account
- **preconditions**: one account per role, each deployed against the post-change build
- **steps**: for each role, open the nav and click `Dashboard`; separately, navigate directly to `/dashboards/admin`, `/dashboards/teaching`, `/dashboards/my-learning` by URL
- **expected result**: each role sees exactly one `Dashboard` nav entry (never three, never zero) landing on the correct role view of `ScholiqDashboards`; all three direct URLs still render their existing page with no 404
- **test command**: `/test-persona-noor` (admin/functional-admin angle) plus `/test-functional` for the teacher/learner passes

### TC-3: Engagement (and the other six Progress source groups) survive as labeled nested sub-groups
- **spec_ref**: `openspec/changes/menu-six-main-items/specs/navigation/spec.md#requirement-groups-with-children-preserve-their-identity-when-folded-into-a-new-parent`
- **type**: functional
- **persona**: n/a
- **preconditions**: post-change build
- **steps**: expand the `Progress` top-level item; inspect its immediate children
- **expected result**: exactly 7 labeled children (`Engagement`, `Course evaluation`, `Competencies`, `Progress & analytics`, `Portfolios`, `Study progress (BSA)`, `BPV`), each itself expandable to its own original leaves — not a flat list of ~28 items
- **test command**: `/test-functional`

### TC-4: Full page-id set is unchanged
- **spec_ref**: `openspec/changes/menu-six-main-items/specs/navigation/spec.md#requirement-no-page-or-route-is-dropped-by-the-six-group-collapse`
- **type**: regression
- **persona**: n/a (build-time check)
- **preconditions**: pre-change git ref and post-change working tree
- **steps**: compute the effective `pages[]` array via `buildManifest()` for both trees; diff the two arrays by id (ignoring which menu leaf, if any, still points at each)
- **expected result**: identical 277-entry sets — same ids, routes, types, components
- **test command**: `/test-regression`

### TC-5: Existing Gate-19 e2e route table passes unmodified
- **spec_ref**: `openspec/changes/menu-six-main-items/specs/navigation/spec.md#requirement-no-page-or-route-is-dropped-by-the-six-group-collapse`
- **type**: regression
- **persona**: n/a
- **preconditions**: `tests/e2e/pages.spec.ts` unmodified, post-change deployment
- **steps**: run the existing route-smoke suite
- **expected result**: 100% pass, zero new failures, zero route-table edits required
- **test command**: `/test-regression`

### TC-6: Compliance leaf/group name collision is resolved
- **spec_ref**: `openspec/changes/menu-six-main-items/specs/navigation/spec.md#requirement-the-top-level-nav-presents-six-named-destinations-plus-two-pending-extractions`
- **type**: functional
- **persona**: n/a
- **preconditions**: post-change build
- **steps**: expand the `Compliance` top-level group; find its overview child (former `Compliance` leaf, route unchanged)
- **expected result**: the child reads "Overview," not "Compliance" — no parent/child label duplication; the route (`Compliance`) and page id are unchanged from before this change
- **test command**: `/test-functional`

### TC-7: Accessibility (WCAG) pass on the deeper nav tree
- **spec_ref**: `openspec/changes/menu-six-main-items/specs/navigation/spec.md#requirement-groups-with-children-preserve-their-identity-when-folded-into-a-new-parent`
- **type**: accessibility
- **persona**: Jasper (screen-reader-primary)
- **preconditions**: post-change build, `Progress`'s 3-level nested tree present (Decision 1's known trade-off)
- **steps**: navigate the `Progress` → `Engagement` → leaf nesting with a screen reader and keyboard only
- **expected result**: each level's expand/collapse state and nesting depth is announced correctly; no focus trap; this test is expected to surface exactly the usability cost Decision 1 already names (deeper nesting = more keystrokes to reach a leaf) — record findings for the follow-up card-grid change rather than treating them as a regression to fix here
- **test command**: `/test-accessibility`

## Coverage Summary

- "Six/eight top-level destinations" requirement: TC-1.
- "Dashboard resolves by role" requirement: TC-2.
- "Groups preserve identity when folded" requirement: TC-3, TC-7.
- "No page or route dropped" requirement: TC-4, TC-5.
- Compliance label-collision fix (Decision 4, not a separate formal requirement): TC-6.

## Out of Scope

- Testing Compliance's or My learning's promised-but-not-yet-built content (coverage tracking, regulations, attestations, audit pack, portfolio/meetings/electives) — none of it exists as pages yet (proposal Out of Scope).
- A/B comparing the nested-group Progress tree against a hypothetical card-grid landing page — that comparison is for the follow-up change once (if) it's scoped.
