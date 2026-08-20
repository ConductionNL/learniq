---
kind: config
depends_on:
  - manifest-fragment-split
---

# Proposal: menu-six-main-items

> **⚠️ AMENDED 2026-08-20 — read design.md "Decision 1" first; it overrides the
> nesting language throughout this file, tasks.md and test-plan.md.**
>
> This proposal was written when no component in `@conduction/nextcloud-vue`
> could render a grid of arbitrary navigation links, so Progress and Compliance
> were specified as NESTED sub-groups — knowingly reintroducing the 3-level
> depth ADR-044 exists to eliminate.
>
> `@conduction/nextcloud-vue` **2.8.0** now ships `CnNavCardGrid`, registered as
> the built-in widgetKey `nav-card-grid`. Verified in the installed package: 216
> occurrences of `nav-card-grid` in `dist`, including the compiled manifest
> validator.
>
> **Progress and Compliance therefore get real card-grid landing pages**, one
> card per former sub-group, and nav depth stays at two. Wherever this file says
> those two "nest", read "one top-level entry routing to a `type: "dashboard"`
> page carrying a single `nav-card-grid` widget". The mechanism for the OTHER
> folds (People's sub-groups, Learning's timetabling leaves) is unchanged, and
> Decision 2's finding — that `applyMenuRelocations` DISSOLVES a relocated group
> and discards its label — still stands and is still why those use fragment
> nesting rather than relocations.
>
> This adds a hard floor of `@conduction/nextcloud-vue >= 2.8.0`; the pin moves
> from `^2.3.0` and the lockfile with it, as part of this change.


## Summary

Collapses Scholiq's top-level navigation from 24 measured main-nav groups down to six named destinations (**Dashboard, Learning, People, Progress, Compliance, My learning**) plus the two groups already scheduled for extraction by other changes (**Data exchange** → `openconnector-flow-migration`, **Payments** → a future pipelinq change), which this change deliberately leaves untouched. It also finally completes work `nav-restructure-dashboards` started but did not finish: `GroupInsight` — measured today as still rendering non-empty with four leftover Accessibility/Compliance children — fully dissolves once this change relocates its last children out.

## Motivation

`manifest-fragment-split` gave every future group a clean file boundary; this change is the actual information-architecture edit users will see. Twenty-four top-level items (measured — see design.md "Correction to the brief") is a scanability problem repeated across every persona: an admin, teacher, and learner each face the same wall of groups whether or not it applies to them. Grouping by the question each destination answers ("what am I working on" → Learning/People, "how are they doing" → Progress, "are we compliant" → Compliance, "what's mine" → My learning) matches how the six-group model already proved out for Learning and People in `nav-restructure-dashboards`.

## Affected Projects

- [ ] Project: `scholiq` — `src/menu-layout.json` relocations/removals edits; `src/manifest.d/*.json` fragment edits (label/route additions and internal restructuring, no new pages or components); zero PHP, zero new Vue components.

No other apps-extra project is touched.

## Scope

### In Scope

1. **Dashboard** — add one new top-level menu entry pointing at the existing role-aware `Dashboard` page (route `/`, component `ScholiqDashboards`, currently reachable only as the implicit landing route with no menu entry at all). Retire the `DashboardAdmin`/`DashboardTeacher`/`DashboardStudent` menu leaves via `menu-layout.json#removals` — their pages (`/dashboards/admin`, `/dashboards/teaching`, `/dashboards/my-learning`) stay routable, satisfying the invariant.
2. **Learning** — no new top-level item (already exists, navigable + collapsible, per `nav-restructure-dashboards`). Nest `GroupTimetabling`'s 3 children under `GroupLearning` (sessions/rooms/scheduling read as Learning content).
3. **People** — no new top-level item (already exists, same pattern). Nest `GroupAdmissions`, `GroupPupilDossier`, and `GroupConferences` (guardian meetings) under `GroupPeople`, each preserving its own label as a labeled sub-heading.
4. **Progress** — new top-level entry. Nests `GroupEngagement`, `GroupCourseEvaluation`, `GroupCompetency`, `GroupStudentAnalytics`, `GroupPortfolio`, `GroupStudyProgress` (BSA), and `GroupBpv` (work placement) as seven labeled sub-groups under one `GroupProgress` parent. See design.md "Decision 1" for why this is nested sub-groups rather than a literal card-grid landing page.
5. **Compliance** — new top-level entry. Relocates the flat leaves `Compliance` (existing overview page, relabeled to avoid a parent/child name collision — see design.md), `ExternalTraining`, `Accessibility`, `AccessibilityLimitationsMenu`, `AiProcessingDisclosureMenu`, `AccessibilityFeedbacksMenu` as direct children, and nests `GroupExamBoard` as one labeled sub-group. This is the last of `GroupInsight`'s children moved out, so `GroupInsight` itself finally dissolves (empty-shell pruning, `buildManifest`'s existing behaviour).
6. **My learning** — new top-level entry. Relocates the flat leaves `MyTimetableMenu` and `MyLearningRecordMenu` as direct children. Portfolio/meetings/electives content does not exist as separate pages yet — out of scope, see Open Questions.
7. Footer (`Documentation`, `Features & roadmap`) and the settings foldout (`xAPI statements`, `School-year rollover`) are unchanged.
8. **Data exchange and Payments are explicitly left alone.** After this change, the nav has eight top-level main items, not six: the six named above, plus `GroupDataExchange` and `GroupPayments` untouched, pending their own extraction changes (`openconnector-flow-migration` for the former; a future pipelinq-extraction change for the latter). Treat "six main items" as the steady-state target once those two land, not this change's own end state.

### Out of Scope

- Deleting or relocating `GroupDataExchange` or `GroupPayments` — owned by other changes (see above).
- Building any new page, dashboard component, or card-grid UI. All six destinations reuse pages that already exist; Progress and Compliance ship as nested collapsible groups, not card-grid landing pages, in this change (design.md "Decision 1" states the concrete reason).
- Filling Compliance's promised-but-not-yet-built content (coverage tracking, regulations register, attestations, audit pack) — the task brief that motivated this change names these as the "v0.1 headline wedge," but as of this measurement none of them exist as pages. This change organizes what exists (`Compliance` overview, `ExternalTraining`, three Accessibility leaves, AI-processing-disclosure, Exam board); building the rest is future work.
- Filling My learning's promised-but-not-yet-built content (portfolio, meetings, electives for the learner's own view) — same reasoning; only `MyTimetableMenu`/`MyLearningRecordMenu` exist today.
- Any sector-profile visibility toggle on the module fragments `manifest-fragment-split` isolated (work-placement, guardian-meetings, admissions, pupil-record, assessment-board, progress-decisions) — this change relocates/nests their content but does not add `visibleIf` gating logic.
- Renaming BPV→"Work placement" or Pupil dossier→"Learner record" — the task's naming brief explicitly reserves this; only the one required relabel (the `Compliance` leaf, to resolve its collision with the new `Compliance` group's own label) is made, and only because a translation key is added for it.

## Approach

`menu-layout.json` relocations (Mechanism A) handle every genuinely flat leaf being re-homed (the three retired Dashboard leaves via `removals`, and the Compliance/My-learning/Learning-timetabling flat leaves via `relocations`). Every fold whose source is itself a *group* — one that has its own `children[]` and therefore its own identity worth preserving (Admissions, Pupil dossier, Parent conferences, Engagement, Course evaluation, Competencies, Progress & analytics, Portfolios, Study progress, BPV, Exam board) — is handled instead by directly restructuring the `manifest.d/*.json` fragments so the new parent (`GroupPeople`, `GroupProgress`, `GroupCompliance`) declares that group as a nested child in the fragment JSON itself (Mechanism B). This is a deliberate, code-verified choice: `buildManifest`'s `applyMenuRelocations` **dissolves** a relocated group, flattening its children directly into the target and discarding the group's own label — confirmed from `@conduction/nextcloud-vue`'s `buildManifest.js` source and its own docstring ("A relocated GROUP dissolves..."). Using `relocations` for a group-with-children would silently flatten e.g. Engagement's 6 differently-named leaves (`Leaderboard`, `Point rules`, `Levels`, `Leaderboards (config)`, `Point awards`, `Learner engagement`) directly into a ~28-item undifferentiated Progress list — several of those labels (`Levels`, `Responses`, `Warnings`, `Overview`-shaped names) are ambiguous without their parent group's context. Mechanism B avoids that by preserving each source group as a genuine, labeled nested child. Full reasoning, including the ADR-044 tension this creates, is in design.md.

## New Dependencies

None.

## Impact

- `src/menu-layout.json` — `removals` gains `DashboardAdmin`/`DashboardTeacher`/`DashboardStudent`; `relocations` drops the now-obsolete `DashboardAdmin`/`DashboardTeacher`/`DashboardStudent` → `__toplevel__` entries (superseded by removal) and adds the flat-leaf relocations listed in Scope point 5/6; `GroupTimetabling`'s current absence from `relocations`/`removals` stays that way since it's handled by fragment restructuring, not relocation.
- `src/manifest.d/dashboard.json` — add the new `Dashboard` top-level menu entry; `GroupInsight`'s remaining structure is unaffected by this file (its children are relocated away via `menu-layout.json`, not edited here).
- `src/manifest.d/learning.json` — nest `GroupTimetabling` under `GroupLearning`.
- `src/manifest.d/people.json`, `admissions.json`, `pupil-record.json`, `guardian-meetings.json` — restructure so `GroupPeople` is the canonical declaration and the other three declare their group as a child of `GroupPeople` instead of an independent top-level id.
- `src/manifest.d/progress.json`, `progress-decisions.json`, `work-placement.json` — same restructuring pattern for the new `GroupProgress`.
- `src/manifest.d/compliance.json`, `assessment-board.json` — same pattern for the new `GroupCompliance`; `compliance.json` additionally relabels the pre-existing `Compliance` leaf page (route/id unchanged) to avoid a parent/child name collision, with a new translation key.
- `src/manifest.d/my-learning.json` — no internal restructuring needed (its two children are already flat leaves); `menu-layout.json` relocations move `MyTimetableMenu`/`MyLearningRecordMenu` under it.
- No `pages[]` additions, removals, or route changes anywhere.

## Cross-Project Dependencies

Depends on `manifest-fragment-split` landing first (same repo) so every group named above has a stable fragment-file home to restructure. Does not depend on `openconnector-flow-migration` or the future pipelinq extraction — Data exchange and Payments are explicitly untouched here (Scope point 8).

## Risks

### Risk 1: Nesting Progress/Compliance's source groups recreates the exact 3-level-deep nav problem ADR-044 exists to eliminate
**Severity:** High — **Mitigation:** documented explicitly, not silently shipped, in design.md "Decision 1." This is a genuine, unresolved architectural tension between this change's `kind: config` constraint (no new Vue components) and ADR-044 §4's cards-collapse remedy (which needs a real card-grid landing page — unavailable declaratively today, see design.md). Flagged as the top DEFERRED_QUESTION in the final report; a follow-up `kind: code` change replacing the nested groups with real card-grid domain dashboards (mirroring the Learning/People precedent) is the recommended resolution once scoped.

### Risk 2: A page or route silently drops during the relocation/restructuring
**Severity:** Medium — **Mitigation:** the same invariant as `manifest-fragment-split` — every one of the 277 effective pages must still resolve before and after. Verification task asserts this by id-set comparison (not just "the app boots"), per ADR-044 §5 / ADR-029.

### Risk 3: The new `Dashboard` top-level entry and the retired per-role dashboard leaves confuse a user who bookmarked `/dashboards/admin` etc.
**Severity:** Low — **Mitigation:** the invariant only requires the route stay resolvable, which it does; the bookmark still works, it's simply no longer reachable by clicking through the nav. Acceptable per ADR-044 §5's explicit allowance ("removals may retire a duplicate navigation entry whose page is still reachable another way").

### Risk 4: The `Compliance` leaf/group label collision goes unnoticed and ships as parent "Compliance" > child "Compliance"
**Severity:** Low — **Mitigation:** explicitly called out in Scope point 5 and Impact; the relabel task is in tasks.md with its own acceptance criterion.

## Rollback Strategy

Pure frontend/config change, no data migration. Revert the change branch (restores the pre-change `menu-layout.json` and fragment contents) and rebuild. `manifest-fragment-split`'s fragment files are untouched in shape (only their internal content is restructured), so no re-split is needed on rollback.

## Open Questions

- **(Highest priority)** Should Progress and Compliance ship in this change as 3-level nested collapsible groups (deliverable now, `kind: config`, but reintroduces the exact nesting depth ADR-044 §4 exists to eliminate), or should this change stop at Dashboard/Learning/People/My-learning and a follow-up `kind: code` change add real card-grid domain dashboards for Progress/Compliance first? Proceeded with nested groups to stay within the assigned `kind: config` constraint; see design.md "Decision 1" for the full reasoning and DEFERRED_QUESTIONS in the final report.
- Should `GroupBpv` (work placement) fold into Progress as a seventh sub-group, as this proposal does, or does it belong somewhere else (e.g. under People, alongside guardian/admissions content)? The originating brief's explicit "six cards" list for Progress didn't name it, though its surrounding prose did ("work placements"). Proceeded with Progress; flagged as a DEFERRED_QUESTION.
- Is relabeling the pre-existing `Compliance` leaf (to avoid colliding with the new `Compliance` group label) the right call, or should the new group instead be labeled something else (e.g. "Compliance & audit") to leave the existing leaf's label untouched? Proceeded with relabeling the leaf since the group name was specified verbatim by the brief; flagged as a DEFERRED_QUESTION.
