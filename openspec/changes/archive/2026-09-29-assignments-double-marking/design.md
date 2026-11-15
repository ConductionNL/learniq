# Design: assignments-double-marking

## Context

Marking is one teacher and one set of fields today. `MarkSubmissionView.saveAndReturn()` (`src/views/MarkSubmissionView.vue:692-797`) writes `rubricScores`, `proposedGrade` and `feedbackText` onto the `Submission`, creates a `concept` `GradeEntry` (`sourceKind: assignment-submission`, :746) that the teacher later publishes from the gradebook, and fires the `return` transition. The assignments spec keeps grade authority with the teacher: no automated path writes `proposedGrade` or a `GradeEntry` (`openspec/specs/assignments/spec.md:86-95`). This change keeps that rule. It adds a place for each marker's own scores and a final step where one person sets the grade.

## Data model

### `Assignment` (0.4.1 to 0.5.0), `lib/Settings/learniq_register.json:7065`

| property | type | default | meaning |
|---|---|---|---|
| `markersPerSubmission` | integer, 1 to 5 | 1 | How many markers score each hand-in on their own. 1 keeps today's single-marker flow. |
| `finalGradeRule` | enum `manual`, `average`, `highest` | `manual` | How the final grade is proposed once every mark is in. The final grade is always saved by a person. |

### `SubmissionMark` (new, slug `submission-mark`, 0.1.0)

| property | type | notes |
|---|---|---|
| `submissionId` | uuid, `$ref: Submission`, required | |
| `assignmentId` | uuid, `$ref: Assignment`, required | copied at allocation so reads can filter per assignment |
| `markerId` | string, required | Nextcloud user id of the marker |
| `rubricScores` | array | same item shape as `Submission.rubricScores` (`criterionId`, `levelId`, `points`) |
| `proposedGrade` | number, nullable | this marker's grade |
| `feedbackText` | string, nullable | this marker's notes to the teacher in charge, never shown to the learner |
| `submittedAt` | date-time, nullable | stamped by the `submit` transition |
| `lifecycle` | enum `draft`, `submitted` | |
| `tenant_id` | string, required | |

Lifecycle: `submit` (`draft` to `submitted`), with `inputs` requiring `proposedGrade`. A submitted mark is final; a correction is a new allocation by the teacher in charge (`withdraw` back to `draft` by `instructors`, `compliance-officers`, `team-leads` only).

Authorization (the grammar `MagicRbacHandler` enforces, as in `Submission.authorization` at :7697):

- read: `compliance-officers`, `team-leads`; the marker's own row (`match: {markerId: $userId}`); `instructors` only through the aggregation below, not the raw rows of other markers.
- create: `instructors`, `compliance-officers`, `team-leads` (allocation).
- update: the marker's own row while `lifecycle` is `draft`.

A marker who is also the teacher in charge (a member of `team-leads`) reads all rows; that is the Moodle and exam-board practice where the first assessor chairs the agreement.

### `Submission` (0.4.0 to 0.5.0), `lib/Settings/learniq_register.json:7404`

- `markerIds` (array of strings, default `[]`): the allocated markers. Empty means single marking.
- `finalGradeSetBy` (string, nullable) and `finalGradeRuleApplied` (enum `manual`, `average`, `highest`, nullable): who set the final grade and which rule proposed it, for the audit trail.
- `x-openregister-aggregations` on `Submission` over `submission-mark` by `submissionId`: `marksSubmitted` (count where `lifecycle = submitted`), `marksAverage` (avg `proposedGrade` where submitted), `marksHighest` (max `proposedGrade` where submitted). These are read-only numbers for the final-grade panel; they write nothing.

## Flows

1. Allocation. On the submissions list (`src/manifest.d/learning.json`, the assignment's submissions index) a header action "Allocate markers" opens a modal (`src/modals/AllocateMarkersModal.vue`) that takes the markers for all handed-in submissions or for one. `POST /api/assignments/{assignmentId}/markers` calls `SubmissionMarkAllocationService::allocate()`, which, like `PeerReviewAllocationService::allocate()` (`lib/Service/PeerReviewAllocationService.php:110`), is idempotent: it tops up `SubmissionMark` drafts to the chosen markers and never duplicates a (marker, submission) pair. It writes `Submission.markerIds`. It refuses more markers than `markersPerSubmission` and refuses a marker who is one of the submission's `learnerIds`.
2. Marking. `MarkSubmissionView` checks `assignment.markersPerSubmission`. When it is above 1 and the current user is in `submission.markerIds`, the rubric and grade fields write into that user's `SubmissionMark` and the save button fires `submit` on it. The Submission itself is not changed and not returned.
3. Final grade. When `marksSubmitted` equals the number of allocated markers, the view shows a final-grade panel to an allocated marker (whose own mark is then `submitted`) and to `compliance-officers` and `team-leads`: every submitted mark side by side (scores per criterion, grade, notes) and a final grade field. With `finalGradeRule` `average` or `highest` the field starts at `marksAverage` or `marksHighest`; with `manual` it starts empty. Save runs the existing `saveAndReturn()` with that value and additionally writes `finalGradeSetBy` and `finalGradeRuleApplied`. The `GradeEntry` and the `return` transition are unchanged.

## Declarative versus imperative

| behaviour | path | reason |
|---|---|---|
| `SubmissionMark` lifecycle | declarative, `x-openregister-lifecycle` | a plain state machine |
| marks submitted, average, highest | declarative, `x-openregister-aggregations` on `Submission` | a count, an average and a maximum over child rows |
| who may read another marker's mark | declarative, `authorization` | the register grammar covers it |
| allocation of markers | imperative, `SubmissionMarkAllocationService` | ADR-031 exception already taken for peer review: a batch over a set of submissions with idempotent top-up, which a per-object declaration cannot express |
| final grade | human action in `MarkSubmissionView` | grade authority stays with a person (assignments spec, `openspec/specs/assignments/spec.md:86-95`) |

The "read another marker's mark only after submitting your own" rule does not fit a single `match`, because it depends on a second row. The allocation service therefore writes it as data: it does not grant read on other rows at all; the final-grade panel reads other markers' marks through a controller method `GET /api/submissions/{id}/marks` that returns other rows only when the caller's own mark is `submitted` or the caller is in `compliance-officers` or `team-leads`. The method carries `#[NoAdminRequired]` with that check in its body (gate 7).

## Seed data

In `lib/Settings/learniq_mock_register.json`, on the HBO example assignment "Afstudeerverslag" (a thesis report):

- `Assignment` "Afstudeerverslag bedrijfskunde", `markersPerSubmission: 2`, `finalGradeRule: manual`.
- Two `SubmissionMark` rows for one submission: marker "j.devries" (`proposedGrade: 7.5`, notes "Sterke analyse, conclusie te kort"), marker "a.bakker" (`proposedGrade: 6.8`, notes "Methode onvoldoende verantwoord"), both `submitted`.
- One `SubmissionMark` in `draft` on a second submission, to show a mark still waiting.

## Risks and open points

- The aggregation engine must support `avg` and `max` over a filtered child set; if it only supports `count` and `sum`, the panel computes average and highest in the view from the rows `GET /api/submissions/{id}/marks` returns (named in task 3).
- `Submission.markerIds` duplicates the set of `SubmissionMark.markerId`; it exists so the view can decide the mode without a second read. The allocation service is the only writer.
