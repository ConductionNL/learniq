---
kind: code
---

# Proposal: cohort-gradebook-batch-publish

## Summary
The cohort gradebook gets a publish panel under the grid. The teacher picks one column or all of
them, sees how the concept marks are spread (count, average, lowest, highest, how many pass, a
histogram), and publishes every concept mark in that scope with one confirmed action. Today a
teacher publishes grades one by one on each grade's own page.

## Motivation
Round 2 recon C, section 1 ("Gradebook publish is per-entry only; the spec's own batch/preview flow
does not exist"): `CohortGradebookView.vue` saves concept `GradeEntry` rows but has no bulk publish
and no distribution preview. Publishing happens one entry at a time on `GradeEntryDetail`. The
grading spec's own acceptance criterion (`openspec/specs/grading/spec.md`) promises the flow: "a
teacher saves a cohort's GradeEntries as concept and opens the distribution preview ... on batch
publish". So does the archived grading design (`openspec/changes/archive/grading/design.md` 4.2:
"Distribution preview: simple histogram ... 'Publish all' button").

The recon also asked for a Gibbon-style grid. That part already exists: the view renders a
`CnDataMatrix` with one row per learner and one column per plan component (learniq#947). This change
adds only what is missing.

Capability row 7.1 (gradebook per class and subject with weighting and period averages; learniq
"built"). Competitor evidence: Gibbon Markbook, as cited in recon C section 2: a per-class gradebook
grid with permission variants. The round 1 corpus names the whole-class grid as the surface to match.
The grading spec itself names the concept then preview then publish order as the answer to Magister
and SOMtoday's instant per-grade pings. Rung 3: a panel on an existing custom page.

## Affected Projects
- [x] Project: `learniq`: a publish panel in `CohortGradebookView.vue`, a pure helper module,
  catalogue strings.

## Scope

### In Scope
- `src/utils/gradebookPublish.js`: the scope of a publish (one component or all), the concept marks
  it would publish, and the distribution of the marks in scope, with pass counts from the plan's
  `GradeScale.passThreshold` when it has one.
- In `CohortGradebookView.vue`: a component picker, the distribution, a histogram with text counts,
  and "Publish N marks" with an inline confirmation. Each concept entry then gets the existing
  `publish` transition, one after another. The panel reports how many were published and which
  could not be, with the server's reason (for example a locked report period).
- Node tests for the helpers; English and Dutch catalogue strings.

### Out of Scope
- One notification per recipient per batch. Each published grade still notifies on its own,
  shaped by the recipient's instant or daily-digest choice (OpenRegister's notification
  preferences). A digest per batch needs a notification change of its own; named in the PR.
- A per-publish `visibleFrom` override; the plan's `gradeVisibilityPolicy` keeps deciding.
- Changes to `GradeEntry`, its lifecycle or its guards.

## Approach
Frontend only. The `publish` transition, its `ReportPeriodLockGuard`, the `gradePublished`
notification and `GradeRollupHandler` stay as they are; the panel fires the same transition the
detail page fires, once per entry.

## New Dependencies
None.

## Impact
- `src/views/CohortGradebookView.vue`, new `src/utils/gradebookPublish.js`,
  new `tests/unit-js/gradebookPublish.test.mjs`, `l10n/en.json`, `l10n/nl.json` (+ generated).

## Cross-Project Dependencies
None. With #1020 (`gradeentry-learnerref-stamp`) landed, each publish transition also stamps
`learnerRef`, so the published grades reach the portal.

## Risks

### Risk 1: Many requests for a big cohort
**Severity:** Low. **Mitigation:** transitions run one at a time with a progress count; a failure
on one entry never stops the rest, and the panel lists what failed.

### Risk 2: Publishing more than the teacher meant
**Severity:** Medium. **Mitigation:** the scope defaults to one component, the button names the
count, and an inline confirmation repeats it before anything is sent.

## Rollback Strategy
Revert the PR; publishing falls back to one grade at a time.
