---
kind: code
depends_on: []
---

# Proposal: assignments-double-marking

## Summary

A teacher in charge of an assignment can ask two markers (or up to five) to mark the same hand-in on their own, and then settle one final grade: agreed by hand, the average or the highest. Each marker's scores live in their own `SubmissionMark`, the second marker does not see the first mark until they have handed in their own, and only the agreed final grade reaches the gradebook through the existing marking path.

## Why

Matrix: learniq `openspec/parity/capabilities.json`, row `asg-two-markers-agree-a-grade` ("Let two markers mark the same hand-in and agree one final grade."), rated `no` with `built.state: none`. Decision: build, two competitors rate yes.

- Demand row (changelog, counted as competitor evidence): https://moodle.atlassian.net/browse/MDL-86006, Moodle 5.2 "Assignment Marking Workflows - Phase 1: Support multiple markers and grade calculations (MDL-86006)"; MDL-86006: "There can be between 1 and 5 markers allocated to a student submission".
- moodle, yes: "source read at moodle/moodle v5.2.3: public/mod/assign/mod_form.php:246-248 (number of markers, up to ASSIGN_MULTIMARKING_MAX_MARKERS) + :255-278 (final grade manual, highest or average) + public/mod/assign/batchsetallocatedmarkerform.php:63; new in 5.2."
- moodle-workplace, yes: https://docs.moodle.org/502/en/Assignment_settings "Multiple markers can be assigned to a single assignment submission", and the final grade is set by Manual ("the teacher in charge sets the final grade manually"), First, Maximum or Average.
- totara, partial: https://totara.help/docs/assignment-activity-settings, multiple marking rounds and marker allocation, but two markers agreeing one grade is not documented.

Double marking is common practice for final projects, theses and PTA work in Dutch MBO, HBO and VO exam boards, where a second assessor (tweede beoordelaar) is required.

## What learniq has today

Read at learniq `development` a84b6273.

- `src/views/MarkSubmissionView.vue:692-797` `saveAndReturn()`: one teacher writes `rubricScores`, `proposedGrade` and `feedbackText` onto the `Submission` (PUT, :706-723), creates a `concept` `GradeEntry` with `sourceKind: assignment-submission` (:731-776) and fires `return` (:779-791).
- `lib/Settings/learniq_register.json:7404` `Submission` (0.4.0): one `rubricScores` array, one `proposedGrade`, one `feedbackText`; lifecycle `draft`, `submitted`, `late`, `returned` (:7577).
- `lib/Settings/learniq_register.json:7065` `Assignment` (0.4.1): no marker count, no final grade rule.
- The built parity evidence: a search of `src/` and `lib/` for second marker, double mark, agreed grade, markers and moderat found no marking hits.
- `lib/Service/PeerReviewAllocationService.php:110` `allocate()` is the one existing allocation over an assignment's submissions, reached by `POST /api/peer-review/{assignmentId}/allocate` (`appinfo/routes.php:155`).

## What this change builds

1. `Assignment.markersPerSubmission` (integer 1 to 5, default 1) and `Assignment.finalGradeRule` (`manual`, `average`, `highest`; default `manual`).
2. A `SubmissionMark` schema: one marker's `rubricScores`, `proposedGrade` and `feedbackText` for one submission, with lifecycle `draft` to `submitted`, and an authorization block that lets a marker read another marker's mark only after submitting their own.
3. Marker allocation: the teacher in charge names the markers per submission (all submissions at once or one by one) from the submissions list. Allocation creates one draft `SubmissionMark` per marker.
4. `MarkSubmissionView` in double-marking mode: a marker scores into their own `SubmissionMark`; the teacher in charge sees every submitted mark side by side and sets the final grade, prefilled by `average` or `highest` only when that rule is chosen, and saves it through the existing `saveAndReturn()` path.
5. With `markersPerSubmission` at 1 nothing changes.

## Out of scope

- A third marker when two marks differ by more than a set margin (a later change if schools ask).
- Rubric criteria per marker (each marker scores the same rubric).
- Anonymous marking of the learner's identity (a separate capability).
- Double marking of `AssessmentResult` (tests); this change is for assignments.

## Affected projects

- [x] `learniq`: `lib/Settings/learniq_register.json` (Assignment, new SubmissionMark), `lib/Settings/learniq_mock_register.json`, `src/views/MarkSubmissionView.vue`, `src/manifest.d/learning.json`, a small allocation service and route, l10n.

## Risks

- Markers see each other's work too early. Mitigation: the `SubmissionMark` read rule opens another marker's mark only when the reader's own mark is `submitted`; the teacher in charge reads all.
- Grade authority. Mitigation: no code path writes `Submission.proposedGrade` or a `GradeEntry` from a rule on its own; the teacher in charge saves the final grade, as today.
