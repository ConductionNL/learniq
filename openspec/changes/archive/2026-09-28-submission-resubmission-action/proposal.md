---
kind: code
---

# Proposal: submission-resubmission-action

## Summary
A teacher can ask a pupil to hand in returned work again, with a new date. The submission detail
page gets an "Ask to hand in again" button on returned work. It fires the `reopen` transition the
schema already declares and asks for the date. The pupil gets a notification, and the pupil can
hand in on time up to that date, even when the assignment's own deadline has passed.

## Motivation
Round 2 recon C, section 1 ("Resubmission is declared in the schema and unreachable from any UI"):
`Submission` declares `reopen` (`returned` to `draft`, `lib/Settings/learniq_register.json`), but
`SubmissionDetail` (`src/manifest.d/learning.json`) declares no `lifecycleActions`, and
`MarkSubmissionView.vue` only fires `return`. Journey step 8 in the recon: "Feedback WORKS;
resubmission DOES NOT WORK".

Building this found a second gap that would have made the button useless. Resubmission is almost
always requested after marking, so after the deadline. `SubmissionWindowGuard` judges a hand-in
against `Assignment.dueAt` only. A reopened submission would then be refused outright, or, when
the assignment accepts late work, handed in as `late` and docked `latePenaltyPercent` for doing what
the teacher asked. The request therefore carries its own date, and the guard honours it.

Capability row 6.1 (assignments with submissions, deadlines and rubrics). Competitor evidence:
Moodle, round 1 `moodle/round1/M1-moodle-column.md` row 6.1, `public/mod/assign` with due and
cut-off dates per submission. The round 1 corpus has no separate resubmission row; the primary
evidence is learniq's own lifecycle, which declares `reopen` and never reaches it. Rung 3: an
action on an existing detail page.

## Affected Projects
- [x] Project: `learniq`: `Submission` gains `resubmissionDueAt` and a notification, `reopen` gains
  an input and staff-only authorization, `SubmissionWindowGuard` honours the new date,
  `SubmissionDetail` gets the button.

## Scope

### In Scope
- `Submission.resubmissionDueAt` (date-time, nullable): the date a requested resubmission is due.
- `reopen` transition: `inputs: [{ field: resubmissionDueAt, required: true }]` and
  `authorization: [instructors, compliance-officers, team-leads]`.
- `SubmissionWindowGuard`: when the submission carries `resubmissionDueAt`, the hand-in window is
  judged against it instead of `Assignment.dueAt`. Late hand-in after that date still follows
  `allowLateSubmission`.
- `SubmissionResubmissionDateListener`: only staff may write `resubmissionDueAt`. A learner may
  create a Submission and edit their own draft, so without it a pupil could set their own date and
  bypass the deadline.
- `x-openregister-notifications.resubmissionRequested` on `Submission`: the learners of the
  submission get a notification when `reopen` fires.
- `SubmissionDetail.config.lifecycleActions` with one declared transition, `reopen`, labelled "Ask
  to hand in again".
- Schema version bumps, catalogue strings, PHPUnit and register tests.

### Out of Scope
- A resubmission counter or history view; the audit trail already records every transition.
- Reopening from `MarkSubmissionView`.
- Group submissions (deferred, no demand).

## Approach
Declarative where the register can say it (input, authorization, notification, manifest button),
one small change in the existing guard where it cannot (which deadline applies).

## New Dependencies
None.

## Impact
- `lib/Settings/learniq_register.json`: `Submission` property, transition, notification; version
  bumps.
- `lib/Lifecycle/SubmissionWindowGuard.php` and its test.
- New `lib/Listener/SubmissionResubmissionDateListener.php`, registered in
  `IntegrityListenerRegistrar`, and its test.
- `src/manifest.d/learning.json` (`SubmissionDetail`).
- `l10n/en.json`, `l10n/nl.json` and the generated catalogues.

## Cross-Project Dependencies
None.

## Risks

### Risk 1: A pupil sees the button on their own returned work
**Severity:** Low. **Mitigation:** the manifest's lifecycle buttons cannot be role-gated, and
OpenRegister's `available-actions` does not filter on per-transition `authorization`. The server
refuses the transition for anyone outside the three staff groups, so a pupil who clicks gets a
refusal and nothing changes. Named as a follow-up for nextcloud-vue or OpenRegister.

### Risk 2: The guard reads the assignment through a top-level findAll config
**Severity:** Medium, inherited. **Mitigation:** not changed here. `loadAssignment()` passes
`register`/`schema` at the top level of the `findAll` config, which OpenRegister ignores. Reported
in the PR.

## Rollback Strategy
Revert the PR. `resubmissionDueAt` stays on rows that received one and is ignored by the old
guard.
