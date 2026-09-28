---
kind: code
---

# Proposal: assignment-missing-submissions-view

## Summary
The assignment page gets a hand-in status section for teachers: "18 of 24 handed in", then the
pupils who have not, split into "not started" and "started, not handed in", and marked overdue
once the due date has passed. The roster is the assignment's group, the same roster the attendance
register uses.

## Motivation
Round 2 recon C, section 1 ("Monitoring who has handed in: half-built"): the Submissions widget on
`AssignmentDetail` (`src/manifest.d/learning.json`, widget `asn-subs`) lists who has handed in.
Nothing computes who has not. A teacher with 24 pupils cannot see the 6 who are missing without
comparing two lists by hand. The attendance register already diffs a session against its cohort
roster (`AttendanceRegisterView.vue`, `attendanceRows()`); assignments never got the same.

Capability row 6.1 (assignments with submissions, deadlines and rubrics; built, gap in monitoring).
Competitor evidence: Moodle, round 1 `moodle/round1/M1-moodle-column.md` row 6.1: "yes
`public/mod/assign` (submissions, due and cut-off dates, ...)", whose grading table shows every
enrolled participant with a submission status. Rung 3: a section on an existing detail page.

## Affected Projects
- [x] Project: `learniq`: a hand-in status section on `AssignmentDetail`, a pure helper module, a
  registry entry, catalogue strings.

## Scope

### In Scope
- `src/utils/handInStatus.js`: pure functions. The roster comes from the assignment's cohort, or
  from every cohort of its course when no cohort is set. Each learner gets a state from their
  submissions: handed in (`submitted`, `late`, `returned`), started (`draft`) or not started. A
  summary counts them.
- `src/components/sections/AssignmentHandInStatus.vue`: a body section, shown to staff only (the
  `teacher`/`admin` dashboard views), with names from LearnerProfile.
- `AssignmentDetail` in `src/manifest.d/learning.json`: one `bodyWidgets` entry.
- `src/registry.js`: `AssignmentHandInStatus` as `kind: "section"`.
- Node tests for the helper, and a registry test that every `bodyWidgets` component is registered.
- English and Dutch catalogue strings.

### Out of Scope
- Reminders to the missing pupils (a notification change, not a view).
- Group submissions (deferred, no demand: PLAN.md "Deferred").
- The portal: pupils and parents do not see the roster.

## Approach
Client-side, like the attendance register: read the assignment, its cohort roster and its
submissions from OpenRegister's object API, diff them in a pure function, render. No new endpoint,
no schema change.

## New Dependencies
None.

## Impact
- New: `src/utils/handInStatus.js`, `src/components/sections/AssignmentHandInStatus.vue`,
  `tests/unit-js/handInStatus.test.mjs`.
- Changed: `src/manifest.d/learning.json` (`AssignmentDetail.config.bodyWidgets`),
  `src/registry.js`, `tests/unit-js/registryComponentCoverage.test.mjs`, `l10n/en.json`,
  `l10n/nl.json` and the generated `.js` catalogues.

## Cross-Project Dependencies
None. Uses `bodyWidgets` from `@conduction/nextcloud-vue` (present in the locked 2.56.0).

## Risks

### Risk 1: A pupil would see classmates as missing
**Severity:** Medium. **Mitigation:** a pupil can read the cohort roster but only their own
submission, so the diff would be wrong for them. The section renders for the `teacher` and
`admin` dashboard views only; for everyone else it renders nothing.

### Risk 2: Large cohorts
**Severity:** Low. **Mitigation:** submissions are read with `_limit: 500` per assignment, names
with the same 1,000-profile read the attendance register uses. A course-wide roster over many
cohorts is capped by the same limits.

## Rollback Strategy
Remove the `bodyWidgets` entry; the component and helper are then unused and can be reverted
with the PR.
