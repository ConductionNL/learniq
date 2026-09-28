---
kind: code
depends_on:
  - segment-menu-gating
---

# Proposal: company-segment-menu-gating

## Summary
A company that picks "Company" in the setup wizard stops seeing the school-only parts of the app: attendance flags and the leerplicht and verzuim reporting, report cards, admissions, school advies, parent conferences (the guardians' menu), BPV and the exam board. An install that never chose a segment keeps every menu, as it does today. The server now tells the browser whether a segment was actually chosen, next to the segment itself, and the school-only entries hide only when the chosen segment is `corporate`.

## Motivation
Decision D26 (Ruben, 2026-09-27, `_round1/compare/decisions.md:30`): "The company segment hides the school-only menu groups; existing installs keep everything until they pick a segment." It answers the open question `segment-menu-gating` (#1041) left in its proposal: that change kept `corporate` in every gate because `corporate` is also the default of every install that never chose (segment-feature-flags Decision 2), so a real company still saw pupil records, report cards and BPV.

The round 1 finding behind the whole chain is 14.6, "feature flags per segment: hide corporate menus for a school" (`_round1/compare/findings.md:51`, rung 1 per `placement.md:68`). Canvas configures feature options per account and sub-account (vendor claim) and Moodle enables or disables features per site (`public/admin/plugins.php`, code path). D26 is the same finding read the other way round: hide school menus for a company.

Measuring what #1041 renders surfaced two defects that this change has to fix to keep its own promise:

1. **Five of #1041's gates never run.** `menu-layout.json` relocates `GroupBpv`, `GroupStudyProgress`, `GroupEngagement`, `GroupCourseEvaluation` and `GroupExamBoard` into other groups. The shared `applyMenuRelocations()` dissolves a relocated group and keeps only its children, so the group's `visibleIf` is dropped. The children are in `removals`, and their real surface is a card on the `ProgressLanding` and `ComplianceLanding` pages, which carries no gate. A primary school therefore still sees the BPV, BSA, leaderboard and course evaluation cards. `segmentMenuGates.test.mjs` evaluated the fragment nodes, not the merged menu, so it could not see this.
2. **Schools lost the whole Compliance menu.** #1041 gated `GroupCompliance` to `corporate` and `training`. After relocation that group also holds the exam board, the accessibility statement, the AI processing disclosure and the privacy requests (D20, #1070). A primary, secondary, MBO or higher education school sees none of them in the navigation.

## Affected Projects
- [x] Project: `learniq`: `SegmentService::chosenSegment()` and a second initial state; `runtime.workspace.chosenSegment` in `src/utils/workspaceRuntime.js`; a reports-card filter; `visibleIf` on the school-only menu entries and on the landing and reports cards; `tests/validate-menu-role-gates.js`; a node matrix test over the merged menu; `docs/installation.md`.

## Scope

### In Scope
- A runtime value that tells "chose Company" apart from "never chose": `runtime.workspace.chosenSegment`, the segment an admin chose, or `null`.
- `visibleIf: {"workspace.chosenSegment": {"notIn": ["corporate"]}}` on every surface of the eight school-only groups the decision names: the Attendance flags reports card (attendance flags, leerplicht and verzuim), report periods, report cards and report card templates, the admissions entries, school advies, the parent conferences entries, the BPV cards and the exam board cards.
- Carry #1041's segment gates down to the surfaces that actually render (defect 1), and move the `GroupCompliance` gate onto the two company cards inside it (defect 2).
- A reports-card filter in `src/main.js` that applies a card's `visibleIf` with the library's own evaluator, because `CnReportsPage` in `@conduction/nextcloud-vue` 2.57.1 ignores it.
- `check:menu-role-gates` learns the new key and checks the cards; a gate on a relocated group now fails the check, because it never runs.
- A node test that builds the menu with the library's `buildManifest()` and evaluates every surface for the seven states (never chose, and each of the six segments).

### Out of Scope
- Hiding pages or data: a hidden entry is not an access boundary; every page stays behind its schema authorization.
- Tabs on detail pages (the Guardians tab on a learner): the decision names menu groups.
- The generated demo data's three `LearniqSettings` rows: they stay, and do not count as a choice (see Approach).
- A native `visibleIf` on reports cards in `@conduction/nextcloud-vue`: a follow-up there lets the filter in learniq go.

## Approach
- `SegmentService::chosenSegment()` returns the stored segment only when the row's `setBy` names an existing Nextcloud user. The wizard always writes the admin's uid. The generated demo rows carry fictional names ("Voorbeeld Setby 1"), so loading demo data is not a choice, which keeps the segment-runtime-bridge promise that demo data does not change the menus.
- `PageController` publishes it as the `chosenSegment` initial state; `buildWorkspaceRuntime()` places it at `runtime.workspace.chosenSegment`, a known code or `null`.
- The gate grammar is AND-only, so one key has to carry the distinction. `notIn: ["corporate"]` passes for `null`, for every school segment and for training, and fails only for a chosen company.
- JSON for the gates; about 60 lines of PHP and JavaScript for the value and the reports filter.

## New Dependencies
None.

## Impact
- `lib/Service/SegmentService.php`, `lib/Controller/PageController.php` and their unit tests.
- `src/utils/workspaceRuntime.js`, new `src/utils/reportCardGates.js`, `src/main.js`.
- `src/manifest.json` (Reports cards), `src/manifest.d/{admissions,assessment-board,compliance,guardian-meetings,learning,progress,progress-decisions,work-placement}.json`.
- `tests/validate-menu-role-gates.js`, `tests/unit-js/segmentMenuGates.test.mjs`, `tests/unit-js/workspaceRuntime.test.mjs`, new `tests/unit-js/reportCardGates.test.mjs`.
- `docs/installation.md`.

## Cross-Project Dependencies
None. The filter uses `passesContextPredicates` from `@conduction/nextcloud-vue/src/utils/visibleIfContext.js` (exported under `./src/*`).

## Risks

### Risk 1: an admin who chose Company on the App settings page still sees school menus
**Severity:** Low. **Mitigation:** a row edited there counts once "Set by" names a user; the wizard always does. The failure mode shows more, never less, which is the pre-D26 behaviour. Documented in `docs/installation.md`.

### Risk 2: schools gain the Compliance menu back
**Severity:** Low. **Mitigation:** that is the #1041 matrix (exam board for secondary and up, accessibility and privacy for everyone); only the company cards inside it keep their gate. The role gate on the group is unchanged.

### Risk 3: a later gate breaks the promise to installs that never chose
**Severity:** Medium. **Mitigation:** `check:menu-role-gates` fails on a `workspace.chosenSegment` predicate other than `notIn`, on an unknown code, and on a segment gate placed on a relocated group.

## Rollback Strategy
Revert the PR. Without the `chosenSegment` initial state every `notIn` gate passes, so the menus return to the #1041 behaviour.

## Open Questions
- Should the hydra mock generator stop emitting rows for singleton settings schemas such as `LearniqSettings`? This change tolerates them instead.
