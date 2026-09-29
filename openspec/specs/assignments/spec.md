---
slug: assignments
title: Assignments & Submissions
status: in-progress
feature_tier: must
depends_on_adrs: [ADR-022, ADR-024, ADR-031]
created: 2026-05-12
updated: 2026-05-12
profiles: [opdracht-vo, opdracht-he, werkstuk, portfolio-item]
openspec_changes:
  - learner-lookup-and-learnerrefs-fixes
---

# Assignments & Submissions

@e2e exclude Pure backend/data-model spec. All requirements define OpenRegister schema shapes, lifecycle guards (late-submission enforcement), and a pluggable plagiarism PHP interface — no `#### Scenario:` headings exist in this spec.

## Purpose

Learners hand work in; teachers grade it. That loop is universal — a vmbo `opdracht`, an HBO `werkstuk`, a university `portfolio-item`, a corporate `case study`. Scholiq can hold courses and lessons but has nowhere for a learner to *submit* anything and nowhere for a teacher to mark it against criteria. This spec adds the deliverable side of assessment (the structured-test side is the `assessment` spec): an `Assignment` belongs to a Course or Session, has a due date and a `Rubric`; a learner files a `Submission` (one or more attachments) which moves through draft → submitted → returned; the grade a teacher gives a Submission becomes a `GradeEntry` (see `grading`) so it can roll up into a final grade per the CurriculumPlan.

## What

- **Assignment** — a deliverable: title, instructions (rich text + attached briefing `Material`s), Course/Session it belongs to, CurriculumPlan component it scores (`componentId`), `dueAt`, `maxPoints`, `allowLateSubmission` + late penalty, `rubricId`, `groupSubmission` (bool), visibility window.
- **Submission** — a learner's (or group's) hand-in for an Assignment: learnerId(s), attached files (OpenRegister attachments), `submittedAt`, `lifecycle` (draft → submitted → late → returned), teacher feedback text, `gradeEntryId` once marked.
- **Rubric** — reusable marking scheme: criteria, each with weighted levels (`{ criterionId, label, weight, levels: [{ label, points }] }[]`). A teacher marking a Submission picks a level per criterion → the points sum is the proposed grade.
- Submission window enforcement, late-flagging + penalty application, plagiarism-check hook (`x-plagiarism` provider config — pluggable, like proctoring; no built-in checker).

## User Stories

- As a teacher, I want to publish an Assignment with a due date and a rubric, so learners know exactly what's expected and how it's marked.
- As a learner, I want to upload my work, save it as a draft, and submit when ready — and see whether it landed before the deadline.
- As a teacher, I want to mark a Submission against the rubric and have the points feed the learner's grade automatically.
- As a learner, I want my returned Submission to show the rubric levels I scored and the teacher's comments.
- As a teacher, I want late submissions flagged and the configured penalty applied to the proposed grade.

## Acceptance Criteria

- GIVEN an Assignment with `dueAt` in the future, WHEN a learner submits, THEN the Submission lifecycle is `submitted` and `submittedAt` is recorded; submitting after `dueAt` sets lifecycle `late`.
- GIVEN `allowLateSubmission=false` and `dueAt` in the past, WHEN a learner attempts to submit, THEN the system rejects it (HTTP 422) and creates no Submission.
- GIVEN a teacher marks a Submission against a Rubric, WHEN they save, THEN a `GradeEntry` is created/updated with the summed points and linked to the Submission; the learner's view shows the per-criterion levels.
- GIVEN a Submission is `late` and the Assignment has a 10% penalty, WHEN the teacher marks it, THEN the proposed grade is reduced by 10% before becoming the GradeEntry value.

## Requirements

### Requirement: Persist Assignment domain objects in OpenRegister
The system MUST persist `Assignment`, `Submission`, `Rubric` as OpenRegister objects with
`x-openregister-lifecycle` (Submission: draft → submitted → late → returned), `x-openregister-relations`
(Assignment↔Course/Session/Rubric, Submission↔Assignment/learner), and `x-openregister-calculations`
(Submission `isLate`, `effectiveGrade`). `Assignment` additionally gains `peerReviewEnabled`,
`selfAssessmentEnabled`, `peerReviewersPerSubmission`, `peerReviewAnonymity` (`open | blind | double-blind`),
`peerReviewAllocationStrategy` (`round-robin | random | manual`), `peerReviewDueAt`, `peerReviewWeightPercent`,
and `selfAssessmentTiming` (`before-submission | after-submission | both`) — all additive, defaulting to
disabled/null so existing rows validate unchanged. The system MUST also persist two new schemas, `PeerReview`
(reviewer × submission × rubric scores, lifecycle `assigned → submitted → released`) and `SelfAssessment`
(learner × own submission × rubric scores, lifecycle `draft → submitted`), plus a computed, read-only
`PeerFeedbackSummary` (one row per `Submission`, aggregated from `released` `PeerReview`s). The existing
`AssignmentPublishGuard` on `draft → published` MUST additionally block publish when `peerReviewEnabled` or
`selfAssessmentEnabled` is `true` but `rubricId` is unset.

#### Scenario: Assignment objects persist in OpenRegister
- **GIVEN** the assignment schemas are registered in OpenRegister
- **WHEN** an `Assignment`, `Submission`, or `Rubric` is created
- **THEN** it is stored as an OpenRegister object carrying its `x-openregister-lifecycle` (Submission: draft →
  submitted → late → returned), `x-openregister-relations`, and `x-openregister-calculations` (Submission
  `isLate`, `effectiveGrade`) metadata

#### Scenario: PeerReview and SelfAssessment persist alongside Assignment
- **GIVEN** an `Assignment` with `peerReviewEnabled: true` and `selfAssessmentEnabled: true`
- **WHEN** a `PeerReview` or `SelfAssessment` is created against one of its `Submission`s
- **THEN** it is stored as an OpenRegister object carrying its own `x-openregister-lifecycle` and a
  `rubricScores` array shaped identically to `Submission.rubricScores`

#### Scenario: Publish is blocked when peer/self assessment is enabled without a Rubric
- **GIVEN** an `Assignment` in `draft` with `peerReviewEnabled: true` and `rubricId` unset
- **WHEN** the `publish` transition is attempted
- **THEN** `AssignmentPublishGuard` blocks it, because there is no `Rubric` for reviewers or the learner to
  score against

### Requirement: Submission attachments use OpenRegister file attachments
Submission attachments MUST use OpenRegister file attachments; no app-local file storage.

#### Scenario: Submission attachments stored as OpenRegister attachments
- **GIVEN** a learner filing a Submission with one or more files
- **WHEN** the files are uploaded
- **THEN** they are stored as OpenRegister file attachments and no app-local file storage is used

### Requirement: Marking a Submission emits a GradeEntry
Marking a Submission MUST emit (or update) a `GradeEntry` consumed by the `grading` spec; this spec MUST NOT
compute final grades itself. Peer and self-assessment scores (`PeerReview.rubricScores`/`totalScore`,
`SelfAssessment.rubricScores`/`totalScore`) MUST NOT be written into `Submission.rubricScores` or
`Submission.proposedGrade` by any automated code path, and MUST NOT create or influence a `GradeEntry`
directly — `GradeEntry.sourceKind` is not extended with a peer/self value. When `Assignment.peerReviewWeightPercent`
is set, it MAY drive a suggested blended number displayed to the teacher in `MarkSubmissionView`, but the
teacher's own entry into `Submission.rubricScores`/`proposedGrade` remains the only write path to a
`GradeEntry`.

#### Scenario: Marking a Submission emits a GradeEntry
- **GIVEN** a teacher marking a Submission against its Rubric
- **WHEN** the marking is saved
- **THEN** a `GradeEntry` is emitted or updated for the `grading` spec, and this spec does not compute the
  final grade itself

#### Scenario: Peer and self-assessment scores never create a GradeEntry
- **GIVEN** an Assignment with `peerReviewEnabled: true`, a `released` `PeerReview`, and a `submitted`
  `SelfAssessment` for a Submission that the teacher has not yet marked
- **WHEN** the `PeerReview` is released or the `SelfAssessment` is submitted
- **THEN** no `GradeEntry` is created or updated, and `Submission.rubricScores`/`proposedGrade` remain
  unchanged until the teacher marks the Submission themselves

#### Scenario: A configured peer-review weight only suggests, never writes, a blended score
- **GIVEN** an Assignment with `peerReviewWeightPercent: 20` and a `PeerFeedbackSummary.averageScore` for a
  Submission the teacher is marking
- **WHEN** the teacher opens `MarkSubmissionView`
- **THEN** a blended suggestion is displayed alongside the teacher's own entry fields
- **AND** `Submission.proposedGrade` is not pre-filled or altered until the teacher explicitly enters a value

### Requirement: Plagiarism check is a pluggable provider
The plagiarism-check hook MUST be a declared `x-plagiarism.provider` config on `Assignment` resolving to a pluggable PHP interface (no bundled provider) — analogous to proctoring providers in the `assessment` spec.

#### Scenario: Plagiarism check resolves to a pluggable provider
- **GIVEN** an Assignment with an `x-plagiarism.provider` config and no bundled checker
- **WHEN** the plagiarism-check hook fires
- **THEN** the configured provider is resolved through the pluggable PHP interface, analogous to proctoring providers in the `assessment` spec

### Requirement: Frontend is declarative with named custom views
The frontend MUST be declarative: `src/manifest.json` pages for Assignment index/detail and a custom
`SubmitWorkModal` + `MarkSubmissionView` Vue component (genuine UI that a manifest index/detail page can't
express). No PHP CRUD controllers, except the narrowly-scoped `PeerReviewController::allocate()` batch-matching
endpoint; the late-window enforcement is an `x-openregister-lifecycle` guard. Two further named custom views,
`PeerReviewMarkingView` (a reviewer completes a `PeerReview` against the Assignment's Rubric) and
`SelfAssessmentView` (a learner completes a `SelfAssessment` against the same Rubric), are added; the existing
`MarkSubmissionView` is extended with a read-only `PeerFeedbackSummary`/`SelfAssessment` context panel.

#### Scenario: Frontend is declarative with named custom views
- **GIVEN** the assignments app frontend
- **WHEN** the UI is composed
- **THEN** Assignment index/detail are declarative `src/manifest.json` pages, the only custom Vue components
  are `SubmitWorkModal`, `MarkSubmissionView`, `PeerReviewMarkingView`, and `SelfAssessmentView`, there are no
  PHP CRUD controllers besides the peer-review allocation endpoint, and late-window enforcement is an
  `x-openregister-lifecycle` guard

#### Scenario: MarkSubmissionView shows peer and self-assessment as read-only context
- **GIVEN** a teacher opening `MarkSubmissionView` for a Submission that has a `PeerFeedbackSummary` and a
  `submitted` `SelfAssessment`
- **WHEN** the view renders
- **THEN** both are shown as read-only context panels, and the teacher's own rubric marking fields remain the
  only editable grade input on the page

### Requirement: Assignment declares which competencies it assesses

The `Assignment` object MUST support a `competencyIds` field (array of `format: uuid` `$ref: Competency`,
default `[]`) declaring which competencies a graded `Submission` for this assignment provides evidence
for. The field MUST be additive — existing `Assignment` rows leave `competencyIds` as an empty array — and
MUST NOT be required. When set, the `competency` capability's `CompetencyAttainmentRollupHandler` MUST
treat every listed competency as aligned when the resulting `GradeEntry` (`sourceKind:
assignment-submission`) publishes.

#### Scenario: A published GradeEntry from an aligned Assignment feeds the competency roll-up

<!-- @e2e exclude Pure OpenRegister schema field; the roll-up behaviour itself is covered by the competency capability's PHPUnit CompetencyAttainmentRollupHandlerTest, not a scholiq DOM surface here. -->

- **GIVEN** an `Assignment` with `competencyIds` set to one `Competency` UUID
- **WHEN** a learner's `Submission` for that assignment is marked and its `GradeEntry` transitions to
  `published`
- **THEN** the `competency` capability's roll-up handler creates or updates a `CompetencyAttainment` row
  for that learner and competency

#### Scenario: An assignment with no declared competencies does not participate in the roll-up

<!-- @e2e exclude Additive-field default-value / no-op handling; no DOM surface. -->

- **GIVEN** a pre-existing `Assignment` row with `competencyIds` unset (defaults to `[]`)
- **WHEN** a `Submission` for it is marked and published
- **THEN** no `CompetencyAttainment` row is created or updated, and grading behaves exactly as it did
  before this change

### Requirement: Peer review and self-assessment are configurable per Assignment
`Assignment` MUST expose `peerReviewEnabled`, `selfAssessmentEnabled`, `peerReviewersPerSubmission` (minimum
1, default 2), `peerReviewAnonymity` (`open | blind | double-blind`, default `blind`),
`peerReviewAllocationStrategy` (`round-robin | random | manual`, default `round-robin`), an optional
`peerReviewDueAt`, an optional `peerReviewWeightPercent` (0–100), and `selfAssessmentTiming`
(`before-submission | after-submission | both`, default `after-submission`). Every field is independently
toggleable — an Assignment MAY enable self-assessment without peer review, or vice versa.

#### Scenario: Peer review and self-assessment default to disabled
- **GIVEN** a newly created `Assignment` with no peer/self fields explicitly set
- **WHEN** it is persisted
- **THEN** `peerReviewEnabled` and `selfAssessmentEnabled` are both `false`, and no reviewer allocation or
  self-assessment prompt occurs

#### Scenario: An Assignment enables self-assessment without peer review
- **GIVEN** an Assignment with `selfAssessmentEnabled: true` and `peerReviewEnabled: false`
- **WHEN** a learner submits their work
- **THEN** they are prompted for a `SelfAssessment` per `selfAssessmentTiming`, and no `PeerReview` allocation
  occurs for this Assignment

### Requirement: Reviewer allocation runs as a dedicated service supporting round-robin, random, and manual strategies
`PeerReviewAllocationService` MUST allocate `PeerReview` rows for an Assignment's submissions, drawing its
reviewer pool from the Assignment's own submitters (not the full cohort), excluding every learner listed in a
Submission's own `learnerIds` from reviewing that Submission. It MUST support `round-robin` (deterministic
cyclic assignment), `random` (shuffled assignment, same exclusion rule), and `manual` (a no-op — the teacher
creates `PeerReview` rows by hand). Re-running allocation for an Assignment MUST be idempotent: it only tops
up submissions short of `peerReviewersPerSubmission` reviewers and never duplicates an existing
(reviewer, submission) pair.

#### Scenario: Round-robin allocates the configured reviewer count while excluding self
- **GIVEN** an Assignment with `peerReviewAllocationStrategy: round-robin`, `peerReviewersPerSubmission: 2`,
  and five Submissions each from a different learner
- **WHEN** `PeerReviewAllocationService::allocate()` runs
- **THEN** every Submission ends up with exactly 2 `PeerReview` rows in `assigned` state, and no learner is
  assigned to review their own Submission

#### Scenario: Manual strategy performs no automatic allocation
- **GIVEN** an Assignment with `peerReviewAllocationStrategy: manual`
- **WHEN** `PeerReviewAllocationService::allocate()` is invoked
- **THEN** no `PeerReview` rows are created; a teacher must create them through the manifest create form

#### Scenario: Re-running allocation is idempotent
- **GIVEN** an Assignment where every Submission already has its full complement of `assigned`/`submitted`/
  `released` `PeerReview`s
- **WHEN** `PeerReviewAllocationService::allocate()` is run again
- **THEN** no new `PeerReview` rows are created and no existing (reviewer, submission) pair is duplicated

### Requirement: PeerReview captures one reviewer's rubric-based assessment with its own lifecycle
`PeerReview` MUST persist `assignmentId`, `submissionId`, `reviewerId`, `rubricScores` (the same
`{criterionId, levelId, points}[]` shape as `Submission.rubricScores`), an optional `totalScore`, and an
optional `comments` field, with lifecycle `assigned → submitted → released`.
`x-openregister-authorization.create` MUST restrict creation to `admin` (system-created via allocation, or a
teacher's manual creation) — a reviewer never authors their own `PeerReview` row, only transitions an
allocated one. The `submit` transition (`assigned → submitted`) MUST be guarded by
`RubricScoresCompletionGuard`, which blocks the transition unless `rubricScores` covers every `criterionId` in
the linked Assignment's `Rubric`.

#### Scenario: A reviewer completes an assigned PeerReview
- **GIVEN** a `PeerReview` in `assigned` state for a reviewer
- **WHEN** the reviewer scores every criterion of the linked Rubric and submits
- **THEN** the `PeerReview` transitions to `submitted`

#### Scenario: Submit is blocked when rubric coverage is incomplete
- **GIVEN** a `PeerReview` in `assigned` state whose linked Rubric has three criteria, and `rubricScores` only
  covers two of them
- **WHEN** `submit` is attempted
- **THEN** `RubricScoresCompletionGuard` blocks the transition

#### Scenario: A teacher releases a submitted PeerReview
- **GIVEN** a `PeerReview` in `submitted` state
- **WHEN** a teacher (or admin) transitions it to `released`
- **THEN** the `PeerReview` becomes eligible for `PeerFeedbackAggregator` to include in the Submission's
  `PeerFeedbackSummary`

### Requirement: Self-assessment lets a learner score their own submission against the Assignment's Rubric
`SelfAssessment` MUST persist `assignmentId`, `submissionId`, `learnerId`, `timing`
(`before-submission | after-submission`), `rubricScores`, an optional `totalScore`, and an optional `comments`
field, with lifecycle `draft → submitted`. `learnerId` MUST be one of the linked `Submission.learnerIds`. The
`submit` transition MUST be guarded by the same `RubricScoresCompletionGuard` used by `PeerReview.submit`.

#### Scenario: A learner completes a self-assessment before submitting
- **GIVEN** an Assignment with `selfAssessmentEnabled: true` and `selfAssessmentTiming: before-submission`
- **WHEN** the learner scores their own draft Submission against the Rubric and submits the `SelfAssessment`
- **THEN** it transitions to `submitted`, independently of the Submission's own `draft → submitted` transition

#### Scenario: A learner completes a self-assessment after submitting
- **GIVEN** an Assignment with `selfAssessmentTiming: after-submission` and a Submission already in
  `submitted` state
- **WHEN** the learner scores their own Submission against the Rubric and submits the `SelfAssessment`
- **THEN** it transitions to `submitted`, and the Submission's own lifecycle is unaffected

### Requirement: A learner hands in their own work and the teacher marks it
The Submission authorization MUST let every signed-in user create a submission, MUST let a learner named in `learnerIds` update it while its lifecycle is `draft` (file upload, saving the references, the `submit` transition), and MUST let `instructors`, `compliance-officers` and `team-leads` read and update it for marking. Because create cannot be narrowed by a match, the `submit` guard MUST refuse a caller who is not in `learnerIds`, except administrators and system calls. Late hand-in MUST be its own transition, `submitLate` (draft to `late`), because a guard cannot change a transition's target state: `SubmissionWindowGuard` MUST allow `submit` only inside the window and `submitLate` only after the deadline of an assignment with `allowLateSubmission`, and the hand-in screen MUST pick the transition that fits the deadline.

#### Scenario: A learner hands in work
@e2e exclude Enforced by OpenRegister from the register JSON and by SubmissionWindowGuard; pinned by tests/Unit/Register/SubmissionAccessTest.php and tests/Unit/Lifecycle/SubmissionWindowGuardTest.php.
- **GIVEN** a learner in no staff group and an open assignment
- **WHEN** the learner hands in a file on the hand-in screen
- **THEN** the draft is created, the file attached and the submission lands in `submitted`

#### Scenario: A learner hands in late work after the deadline
@e2e exclude Guard and register behaviour; pinned by tests/Unit/Lifecycle/SubmissionWindowGuardTest.php (testAfterTheWindowOnlySubmitLatePasses, testRegisterDeclaresSubmitLateIntoLate) and tests/unit-js/customPages.test.mjs (handInAction).
- **GIVEN** an assignment whose deadline has passed and that accepts late work
- **WHEN** the learner hands in on the hand-in screen
- **THEN** the screen fires `submitLate`, the submission lands in `late`, and `submit` on the same draft is refused with a reason

#### Scenario: Nobody hands in work in another learner's name
@e2e exclude Guard behaviour; pinned by tests/Unit/Lifecycle/SubmissionWindowGuardTest.php.
- **GIVEN** a draft submission whose `learnerIds` names learner A
- **WHEN** user B fires `submit`
- **THEN** the transition is refused

### Requirement: A teacher sees who has not handed in an assignment

`AssignmentDetail` MUST show staff a hand-in status section computed from the assignment's roster
and its submissions. The roster MUST be `Cohort.learnerIds` of `Assignment.cohortId`, or the union
of `learnerIds` of every cohort of `Assignment.courseId` when no cohort is set. A learner MUST count
as handed in when a submission for the assignment lists them in `learnerIds` with lifecycle
`submitted`, `late` or `returned`; as started when their only submissions are `draft`; and as not
started otherwise. The section MUST show the handed-in count out of the roster size, then every
learner who has not handed in, and MUST mark them overdue once `Assignment.dueAt` has passed. The
section MUST render nothing for a user without the `teacher` or `admin` dashboard view.

#### Scenario: Six of twenty-four have not handed in

<!-- @e2e exclude Pure diff logic covered by node test tests/unit-js/handInStatus.test.mjs; the section itself renders from that output and lanes do not run against the shared instance. -->

- **GIVEN** an assignment for a cohort of 24 learners, with 18 submissions in `submitted`, `late`
  or `returned`, 2 in `draft`, and 4 learners without a submission
- **WHEN** a teacher opens the assignment
- **THEN** the section shows "18 of 24 handed in", lists 2 learners as started and 4 as not started

#### Scenario: A course-wide assignment uses every cohort of the course

<!-- @e2e exclude Node test tests/unit-js/handInStatus.test.mjs. -->

- **GIVEN** an assignment with no `cohortId` for a course with two cohorts that share one learner
- **WHEN** the roster is built
- **THEN** it holds every learner of both cohorts once

#### Scenario: Missing work is overdue after the due date

<!-- @e2e exclude Node test tests/unit-js/handInStatus.test.mjs. -->

- **GIVEN** an assignment whose `dueAt` has passed and a learner without a submission
- **WHEN** the section is computed
- **THEN** that learner is marked overdue

#### Scenario: A pupil does not see the roster

<!-- @e2e exclude Staff gate covered by node test tests/unit-js/handInStatus.test.mjs (canSeeHandInStatus). -->

- **GIVEN** a user whose dashboard views are only `student`
- **WHEN** they open the assignment
- **THEN** the hand-in status section renders nothing

### Requirement: A teacher allocates peer reviewers from the assignment page

`AssignmentDetail` MUST show staff a peer review section when `Assignment.peerReviewEnabled` is true.
It MUST state the allocation strategy and the reviewers per submission. For `round-robin` and
`random` it MUST offer "Allocate reviewers", which posts to
`/apps/learniq/api/peer-review/{assignmentId}/allocate` and then reports how many reviews were
created across how many submissions. For `manual` it MUST say reviewers are added by hand and offer
no button. Before `Assignment.dueAt` it MUST say that allocating again later adds reviewers for
work handed in since. The section MUST render nothing for users without the `teacher` or `admin`
dashboard view, and nothing when peer review is off.

#### Scenario: A teacher allocates reviewers

<!-- @e2e exclude Section renders from helpers covered by tests/unit-js/peerReviewAllocation.test.mjs; lanes do not run against the shared instance. -->

- **GIVEN** an assignment with `peerReviewEnabled: true` and `peerReviewAllocationStrategy: round-robin`
- **WHEN** a teacher chooses "Allocate reviewers"
- **THEN** the endpoint is called and the section reports the created count and the submissions
  processed

#### Scenario: Manual allocation offers no button

<!-- @e2e exclude Node test tests/unit-js/peerReviewAllocation.test.mjs. -->

- **GIVEN** an assignment with `peerReviewAllocationStrategy: manual`
- **WHEN** a teacher opens it
- **THEN** the section says reviewers are added by hand and shows no allocate button

#### Scenario: Pupils and assignments without peer review show nothing

<!-- @e2e exclude Node test tests/unit-js/peerReviewAllocation.test.mjs. -->

- **GIVEN** a user with only the `student` view, or an assignment with `peerReviewEnabled: false`
- **WHEN** the assignment page renders
- **THEN** the peer review section renders nothing

### Requirement: Allocation reads and writes as the system after the controller's check

`PeerReviewAllocationService` MUST pass `register` and `schema` inside `filters` on every `findAll`,
MUST read and write with `_rbac: false` (its caller has authorized the teacher), and MUST treat only
submissions in `submitted`, `late` or `returned` as work to review and as the reviewer pool.

#### Scenario: A cohort teacher's allocation creates the reviews

<!-- @e2e exclude PHPUnit PeerReviewAllocationServiceTest. -->

- **GIVEN** five handed-in submissions and a caller in `instructors` only
- **WHEN** allocation runs with round-robin and two reviewers per submission
- **THEN** ten `PeerReview` rows are saved with `_rbac: false`, and every read named its schema
  under `filters`

#### Scenario: Drafts are neither reviewed nor reviewers

<!-- @e2e exclude PHPUnit PeerReviewAllocationServiceTest. -->

- **GIVEN** four handed-in submissions and one draft
- **WHEN** allocation runs
- **THEN** the draft gets no reviews and its learner reviews nobody

### Requirement: Every Submission carries server-stamped learnerRefs

Every Submission MUST carry `learnerRefs`: the LearnerProfile UUID of each learner in `learnerIds` who has a profile, found on `ncUserId`. The server MUST derive the list on every create and update and MUST ignore a `learnerRefs` value sent by the client. A learner without a profile MUST add no entry. The stamp MUST NOT block the write: when a lookup fails on create the list is empty, and on an update that keeps the same learners the stored list is kept.

#### Scenario: A pupil's upload reaches the portal

- **GIVEN** pupil `leerling-001` with LearnerProfile `lp-001`
- **WHEN** a Submission is created with `learnerIds: ["leerling-001"]`
- **THEN** it is stored with `learnerRefs: ["lp-001"]`
- **AND** the portal's student submissions collection shows it to that pupil

#### Scenario: A group submission names every member with a profile

- **GIVEN** pupils `leerling-001` and `leerling-002` with profiles, and `leerling-099` without one
- **WHEN** a Submission is created with all three in `learnerIds`
- **THEN** `learnerRefs` holds the two profile UUIDs

#### Scenario: A forged learnerRefs is replaced

- **GIVEN** a client sends `learnerRefs: ["lp-002"]` with `learnerIds: ["leerling-001"]`
- **WHEN** the Submission is created
- **THEN** it is stored with `learnerRefs: ["lp-001"]`

#### Scenario: A failed lookup never blocks the upload

- **GIVEN** the profile lookup fails
- **WHEN** a Submission is created
- **THEN** the write goes through with `learnerRefs: []`

### Requirement: A teacher can ask for returned work to be handed in again

`SubmissionDetail` MUST offer staff an "Ask to hand in again" action on a `returned` Submission. The
action MUST fire the `reopen` transition (`returned` to `draft`) and MUST collect
`resubmissionDueAt` as a required transition input. The `reopen` transition MUST be authorized for
the `instructors`, `compliance-officers` and `team-leads` groups only. When `reopen` fires, the
Submission's learners MUST receive a `resubmissionRequested` notification.

#### Scenario: A teacher reopens returned work with a new date

<!-- @e2e exclude Declarative manifest action plus register transition; covered by PHPUnit SubmissionResubmissionRegisterTest, and lanes do not run against the shared instance. -->

- **GIVEN** a Submission in `returned`
- **WHEN** a teacher chooses "Ask to hand in again" and enters a date
- **THEN** the Submission moves to `draft` with `resubmissionDueAt` set to that date, and its
  learners are notified

#### Scenario: A pupil cannot reopen their own work

<!-- @e2e exclude Register-level authorization; covered by PHPUnit SubmissionResubmissionRegisterTest. -->

- **GIVEN** a Submission in `returned`
- **WHEN** one of its learners, outside the staff groups, fires `reopen`
- **THEN** the transition is refused

### Requirement: A requested resubmission has its own deadline

`SubmissionWindowGuard` MUST judge `submit` and `submitLate` against `Submission.resubmissionDueAt`
when it is set, instead of `Assignment.dueAt`. Before that date `submit` MUST be allowed and
`submitLate` refused; after it the existing late rules MUST apply with that date as the deadline.

#### Scenario: Resubmission after the assignment deadline is on time

<!-- @e2e exclude Lifecycle guard; covered by PHPUnit SubmissionWindowGuardTest. -->

- **GIVEN** an assignment whose `dueAt` has passed and that does not accept late work, and a
  reopened Submission with `resubmissionDueAt` in the future
- **WHEN** the learner fires `submit`
- **THEN** the guard allows it and no late penalty applies

#### Scenario: The resubmission date has passed

<!-- @e2e exclude PHPUnit SubmissionWindowGuardTest. -->

- **GIVEN** a reopened Submission whose `resubmissionDueAt` has passed, on an assignment that accepts
  late work
- **WHEN** the learner fires `submit`
- **THEN** the guard refuses it, and `submitLate` is allowed

#### Scenario: Late hand-in is refused while the resubmission window is open

<!-- @e2e exclude PHPUnit SubmissionWindowGuardTest. -->

- **GIVEN** a reopened Submission with `resubmissionDueAt` in the future
- **WHEN** the learner fires `submitLate`
- **THEN** the guard refuses it

### Requirement: Only staff set a resubmission date

`SubmissionResubmissionDateListener` MUST keep `Submission.resubmissionDueAt` out of the hands of
learners: on create by a caller outside `instructors`, `compliance-officers`, `team-leads` and
admins the value MUST be dropped, and on update by such a caller the stored value MUST be kept.
Staff, admins and system context MUST be able to write it.

#### Scenario: A learner cannot give themselves a later date

<!-- @e2e exclude Pre-write listener; covered by PHPUnit SubmissionResubmissionDateListenerTest. -->

- **GIVEN** a learner's own Submission in `draft` with `resubmissionDueAt` set by their teacher
- **WHEN** the learner updates it with a later `resubmissionDueAt`
- **THEN** the stored date is kept

#### Scenario: A learner cannot create work with a date of their own

<!-- @e2e exclude PHPUnit SubmissionResubmissionDateListenerTest. -->

- **GIVEN** a learner outside the staff groups
- **WHEN** they create a Submission carrying `resubmissionDueAt`
- **THEN** the Submission is stored without it

#### Scenario: A teacher's reopen writes the date

<!-- @e2e exclude PHPUnit SubmissionResubmissionDateListenerTest. -->

- **GIVEN** a teacher in `instructors`
- **WHEN** the reopen transition writes `resubmissionDueAt`
- **THEN** the date is stored

### Requirement: The server stamps who a submission belongs to

`SubmissionOwnerStamp` MUST run on every create and update of a `submission` object, on
OpenRegister's `ObjectCreatingEvent` and `ObjectUpdatingEvent`. It MUST treat a create as a portal
hand-in when there is no Nextcloud session, `learnerIds` is empty and `learnerRef` is set (portaliq
stamps the pupil's LearnerProfile UUID there). For a portal hand-in it MUST read that LearnerProfile
and the Assignment without RBAC and set `learnerIds` to `[profile.ncUserId]`, `learnerRefs` to
`[learnerRef]` and `tenant_id` to the Assignment's tenant (the profile's when the Assignment has
none). It MUST refuse the create when the profile does not exist, is merged away or deleted, when the
Assignment does not exist, or when the profile and the Assignment belong to different tenants. For
every other write it MUST set `learnerRef` to the UUID of the LearnerProfile whose `ncUserId` is
`learnerIds[0]`, preferring one that is not merged away, and MUST ignore a `learnerRef` the client
sent. When no profile matches, `learnerRef` MUST be null. When that lookup fails on an update of a
row whose `learnerIds` did not change, the stored `learnerRef` MUST be kept. After stamping, it MUST
refuse any create or update that has no `learnerIds` or no `tenant_id`, whoever the caller is.
`learnerIds` and `tenant_id` MUST NOT be in the schema's `required` list, because OpenRegister
validates `required` before any listener runs.

#### Scenario: A portal hand-in gets its learner and tenant from the pupil's profile

<!-- @e2e exclude Server-side write listener fed by portaliq's server-to-server create; no DOM surface in learniq. Covered by PHPUnit SubmissionOwnerStampTest::testAPortalHandInIsStampedFromTheProfile. -->

- **GIVEN** a LearnerProfile `lp-1` with `ncUserId: "pupil-1"` and an Assignment `as-1` in tenant `t-1`
- **WHEN** portaliq creates a Submission with `assignmentId: "as-1"`, `learnerRef: "lp-1"` and no Nextcloud session
- **THEN** the stored Submission carries `learnerIds: ["pupil-1"]`, `learnerRefs: ["lp-1"]` and `tenant_id: "t-1"`
- **AND** the write is not stopped

#### Scenario: A portal hand-in for an unknown or merged pupil is refused

<!-- @e2e exclude PHPUnit SubmissionOwnerStampTest (unknown profile, merged profile, missing assignment, tenant mismatch). -->

- **GIVEN** no active LearnerProfile `lp-9`
- **WHEN** portaliq creates a Submission with `learnerRef: "lp-9"`
- **THEN** the create is refused and no Submission is stored

#### Scenario: A staff create without learners is still refused

<!-- @e2e exclude PHPUnit SubmissionOwnerStampTest::testAStaffCreateWithoutLearnersIsRefused. -->

- **GIVEN** a signed-in teacher
- **WHEN** the teacher creates a Submission with `assignmentId` and `tenant_id` but no `learnerIds`
- **THEN** the create is refused with a message that names the learners and the school

#### Scenario: A forged learnerRef from the app is replaced

<!-- @e2e exclude PHPUnit SubmissionOwnerStampTest::testAForgedLearnerRefIsReplaced. -->

- **GIVEN** LearnerProfiles `lp-1` (`ncUserId: "pupil-1"`) and `lp-2` (`ncUserId: "pupil-2"`)
- **WHEN** pupil-1 creates a Submission in the app with `learnerIds: ["pupil-1"]` and `learnerRef: "lp-2"`
- **THEN** the stored Submission carries `learnerRef: "lp-1"`

#### Scenario: An update keeps the stored learnerRef when the lookup fails

<!-- @e2e exclude PHPUnit SubmissionOwnerStampTest::testAFailedLookupOnUpdateKeepsTheStoredRef. -->

- **GIVEN** a Submission with `learnerIds: ["pupil-1"]` and `learnerRef: "lp-1"`
- **WHEN** portaliq attaches a file to it and the profile lookup throws
- **THEN** the stored Submission still carries `learnerRef: "lp-1"`

### Requirement: A reviewer reads the work through a server-side projection

`GET /api/peer-review/{peerReviewId}/work` MUST return, to the PeerReview's `reviewerId` or an
admin and to no one else, a projection of the reviewed Submission with exactly these fields:
`peerReviewId`, `submissionId`, `assignmentId`, `submittedAt`, `anonymity`, `authorIds` and `files`
(`id`, `name`, `size`, `mimetype`). When the caller is the reviewer and the Assignment's
`peerReviewAnonymity` is `double-blind`, `authorIds` MUST be null and every file name MUST be
replaced by `file-N` with the original extension. An Assignment that cannot be read MUST count as
`double-blind`; an unset value MUST count as the schema default `blind`. The projection MUST NOT
contain `learnerIds`, `learnerRefs`, `feedbackText`, `rubricScores`, `proposedGrade` or
`gradeEntryId`. `GET /api/peer-review/{peerReviewId}/work/files/{fileId}` MUST serve a file only
when it belongs to the reviewed Submission, under the projected name. `PeerReviewMarkingView` MUST
read the work from this projection and MUST NOT fetch the Submission.

#### Scenario: A double-blind reviewer sees the work, not the author

<!-- @e2e exclude Server-side projection; covered by PHPUnit PeerReviewWorkProjectionTest and PeerReviewWorkControllerTest. Lanes do not run against the shared instance. -->

- **GIVEN** a double-blind Assignment and Bob's PeerReview of Alice's Submission with the file
  `Alice_de_Vries_essay.pdf`
- **WHEN** Bob requests the work
- **THEN** `authorIds` is null, the file is listed as `file-N.pdf`, and nothing in the response names
  Alice

#### Scenario: Blind and open reviews name the author

<!-- @e2e exclude PHPUnit PeerReviewWorkProjectionTest. -->

- **GIVEN** a `blind` or `open` Assignment
- **WHEN** the reviewer requests the work
- **THEN** `authorIds` lists the Submission's learners and the files keep their names

#### Scenario: Nobody else gets the work

<!-- @e2e exclude PHPUnit PeerReviewWorkProjectionTest and PeerReviewWorkControllerTest. -->

- **GIVEN** a PeerReview reviewed by Bob
- **WHEN** Alice, or any user who is neither Bob nor an admin, requests its work or one of its files
- **THEN** the request is refused with 403

#### Scenario: The teacher's marking never reaches the reviewer

<!-- @e2e exclude PHPUnit PeerReviewWorkProjectionTest. -->

- **GIVEN** a Submission with feedback, rubric scores and a proposed grade
- **WHEN** its reviewer requests the work
- **THEN** none of those fields is in the response

#### Scenario: Only the reviewed Submission's files are served

<!-- @e2e exclude PHPUnit PeerReviewWorkProjectionTest and PeerReviewWorkControllerTest. -->

- **GIVEN** a file id that does not belong to the reviewed Submission
- **WHEN** the reviewer requests it through the file endpoint
- **THEN** the response is 404

### Requirement: Both sides of peer review anonymity are server-enforced projections
`PeerReview.x-property-rbac.read` MUST be a fixed rule — `anyOf: [{role: admin}, {match: {field: reviewerId,
operator: eq, value: $userId}}]` — independent of `Assignment.peerReviewAnonymity`, so a submission's author
can never read a raw `PeerReview` row. The author MUST instead read peer feedback through
`PeerFeedbackSummary`, computed by `PeerFeedbackAggregator` from `released` `PeerReview`s for that Submission:
when `peerReviewAnonymity` is `blind` or `double-blind`, `feedbackItems[].reviewerId` MUST be computed as
`null`; when `open`, it MUST be populated with the reviewer's identity. This is a server-enforced guarantee on
the reviewer-identity axis via object-shape projection, not a UI convention. The reviewee-identity axis for
`double-blind` (hiding whose work a reviewer is grading) MUST be enforced by the server on the reviewer's
path: the reviewer reads the work only through the projection in "A reviewer reads the work through a
server-side projection", which withholds the authors, and never needs read access to the Submission. Staff
who are also a reviewer keep their object-level read on the Submission, which marking needs; that path is
outside this guarantee and documented as such.

#### Scenario: The author cannot read a raw PeerReview
- **GIVEN** a submission author who is neither `admin` nor the `reviewerId` of a given `PeerReview`
- **WHEN** that author requests the `PeerReview` object directly
- **THEN** the request is denied by `x-property-rbac.read` (fail-closed)

#### Scenario: Blind and double-blind hide reviewer identity in the feedback summary
- **GIVEN** an Assignment with `peerReviewAnonymity: blind` (or `double-blind`) and a `released` `PeerReview`
  for one of its Submissions
- **WHEN** `PeerFeedbackAggregator` computes the Submission's `PeerFeedbackSummary`
- **THEN** the corresponding `feedbackItems[].reviewerId` is `null`

#### Scenario: Open anonymity reveals reviewer identity in the feedback summary
- **GIVEN** an Assignment with `peerReviewAnonymity: open` and a `released` `PeerReview` for one of its
  Submissions
- **WHEN** `PeerFeedbackAggregator` computes the Submission's `PeerFeedbackSummary`
- **THEN** the corresponding `feedbackItems[].reviewerId` is populated with the reviewer's identity

#### Scenario: Double-blind reviewee-identity hiding is server-enforced on the reviewer's path

<!-- @e2e exclude Server-side projection; covered by PHPUnit PeerReviewWorkProjectionTest. -->

- **GIVEN** an Assignment with `peerReviewAnonymity: double-blind`
- **WHEN** a reviewer opens `PeerReviewMarkingView` for their assigned `PeerReview`
- **THEN** the view receives the work from the server's projection with `authorIds: null` and neutral file
  names, and makes no request for the Submission itself

### Requirement: Existing submissions are back-filled

On upgrade, every existing Submission MUST get the values the write-path stamps store today: `learnerRefs`, the LearnerProfile uuid of each learner in `learnerIds` who has one, in order and without duplicates, and `learnerRef`, the profile of the first learner or null. The step MUST run after the register is imported, read and write without RBAC or tenant scoping, save only rows whose stored values differ, and never overwrite a stored value when a lookup fails.

#### Scenario: An old group submission reaches the portal

- **GIVEN** a Submission with `learnerIds: ["pupil-1", "pupil-2"]` and no `learnerRefs`, and profiles `lp-1` and `lp-2`
- **WHEN** the repair step runs
- **THEN** the Submission carries `learnerRefs: ["lp-1", "lp-2"]` and `learnerRef: "lp-1"`
- **AND** its other fields are unchanged

#### Scenario: A second run changes nothing

- **GIVEN** the repair step has run
- **WHEN** it runs again
- **THEN** it saves nothing

#### Scenario: A failed lookup leaves the row as it was

- **GIVEN** a Submission with stored `learnerRefs`, and a profile lookup that fails
- **WHEN** the repair step runs
- **THEN** the Submission is not saved and the failure is counted

### Requirement: A pupil hands in a portal draft through learniq's own endpoint

`POST /api/portal/submissions/hand-in` (`PortalSubmissionController::handIn()`) MUST accept only
portaliq's signed forward, in the order of `PortalAssessmentController`: a missing or invalid
`X-Portal-Subject` assertion MUST get 401 and register a failed attempt for throttling; an audience
other than `student`, or a body without `learnerRef`, MUST get 403; a `learnerRef` without an active
LearnerProfile and Nextcloud account MUST get 403 `not_available`. The submission named by the body's
`submissionId` MUST carry the pupil's `learnerRef` or list the pupil's Nextcloud id in `learnerIds`;
otherwise, or when it does not exist, the answer MUST be one 404 `not_found`. A submission whose
`lifecycle` is not `draft` MUST get 409 `already_handed_in`. The endpoint MUST ask
`SubmissionWindowGuard` which hand-in applies for the pupil: `submit` when the guard allows it, else
`submitLate` when the guard allows that; when the guard allows neither, the answer MUST be 422
`late_not_accepted` when the deadline passed on an assignment that takes no late work, else 422
`hand_in_refused`, each with the pupil-facing message, and nothing MUST be written. The chosen
transition MUST run through OpenRegister's `TransitionEngine` as the pupil (`ObjectService::runAs()`),
so the guard runs again on the write; a refusal there MUST be answered the same way. Success MUST be
200 `{submissionId, lifecycle}` with `submitted` or `late`.

#### Scenario: A draft inside the window is handed in

<!-- @e2e exclude Server-to-server receiver with no DOM surface in learniq; the button is portaliq's. Covered by PHPUnit PortalSubmissionHandInTest::testADraftInsideTheWindowIsSubmittedAsThePupil. -->

- **GIVEN** a pupil's draft submission on an assignment due tomorrow
- **WHEN** portaliq forwards `handIn` for it
- **THEN** `submit` runs as the pupil and the answer is 200 with `lifecycle: submitted`

#### Scenario: After the deadline the late rule decides

<!-- @e2e exclude PHPUnit PortalSubmissionHandInTest::testAfterTheDeadlineLateWorkIsHandedInLate and ::testAfterTheDeadlineWithoutLateWorkNothingIsWritten. -->

- **GIVEN** a pupil's draft on an assignment whose deadline passed
- **WHEN** the assignment accepts late work, and when it does not
- **THEN** the first runs `submitLate` and answers `lifecycle: late`; the second answers 422 `late_not_accepted` with the message and fires no transition

#### Scenario: Another pupil's submission is not reachable

<!-- @e2e exclude PHPUnit PortalSubmissionHandInTest::testAnotherPupilsSubmissionIs404. -->

- **GIVEN** a submission whose `learnerRef` and `learnerIds` name another pupil
- **WHEN** a pupil forwards `handIn` with its id
- **THEN** the answer is 404 `not_found`, the same as for an id that does not exist, and no transition runs

#### Scenario: A handed-in submission is not handed in twice

<!-- @e2e exclude PHPUnit PortalSubmissionHandInTest::testASubmissionThatIsNotADraftIs409. -->

- **GIVEN** a pupil's submission that is already `submitted`
- **WHEN** portaliq forwards `handIn` for it
- **THEN** the answer is 409 `already_handed_in` and no transition runs

### Requirement: An assignment can ask for more than one marker

`Assignment` MUST declare `markersPerSubmission` (integer, minimum 1, maximum 5, default 1) and `finalGradeRule` (`manual`, `average` or `highest`, default `manual`). With `markersPerSubmission` at 1 the marking flow MUST behave exactly as before this change. Every `Assignment` stored before this change MUST read as `markersPerSubmission: 1`.

#### Scenario: A thesis assignment asks for two markers

<!-- @e2e exclude Register shape with no screen of its own; the assignment form renders every property. Covered by DoubleMarkingRegisterTest. -->

- **GIVEN** a coordinator editing the assignment "Afstudeerverslag bedrijfskunde"
- **WHEN** they set two markers per submission and the final grade rule to agreed by hand, and save
- **THEN** the assignment stores `markersPerSubmission: 2` and `finalGradeRule: manual`
- **AND** a value of 6 markers is refused by the schema

### Requirement: The teacher in charge allocates markers

A user in `instructors`, `compliance-officers` or `team-leads` MUST be able to allocate markers to the handed-in submissions of an assignment with `markersPerSubmission` above 1, from the assignment's submissions list, for all submissions at once or for one. Allocation MUST create one `draft` `SubmissionMark` per marker and submission, MUST NOT create a second row for a (marker, submission) pair that already has one, MUST refuse more markers than `markersPerSubmission`, and MUST refuse a marker who is one of the submission's learners.

#### Scenario: A coordinator allocates two markers to every hand-in

- **GIVEN** the assignment "Afstudeerverslag bedrijfskunde" with two markers per submission and twelve handed-in submissions
- **WHEN** the coordinator opens the submissions list, chooses "Allocate markers", picks j.devries and a.bakker and confirms
- **THEN** each of the twelve submissions shows both markers
- **AND** running the allocation again adds no second row for either marker

#### Scenario: A learner cannot mark their own group work

<!-- @e2e exclude Service rule; covered by SubmissionMarkAllocationServiceTest::testRefusesAMarkerWhoIsALearnerOfTheSubmission. -->

- **GIVEN** a group submission whose learners include s.jansen, who is also a student assistant in `instructors`
- **WHEN** the coordinator allocates s.jansen as a marker on that submission
- **THEN** the allocation is refused with a reason naming the submission

### Requirement: Each marker scores in their own SubmissionMark

When a submission has allocated markers, the marking screen MUST save a marker's rubric scores, grade and notes into that marker's own `SubmissionMark` and MUST fire `submit` on it. It MUST NOT change or return the `Submission`. A marker's notes in `SubmissionMark.feedbackText` MUST NOT be shown to the learner.

#### Scenario: The first marker hands in a mark

- **GIVEN** j.devries is allocated to a submission of "Afstudeerverslag bedrijfskunde"
- **WHEN** j.devries opens the submission's marking screen, scores the rubric to 7.5 and saves
- **THEN** j.devries's mark shows as submitted
- **AND** the submission is still waiting for marks and has not been returned to the learner

### Requirement: A marker sees other marks only after submitting their own

A marker MUST NOT be able to read another marker's `SubmissionMark` for the same submission while their own mark is `draft`. After their own mark is `submitted`, and for users in `compliance-officers` or `team-leads` at any time, the other marks MUST be readable. The rule MUST hold for the API as well as the screen.

#### Scenario: The second marker cannot peek

<!-- @e2e exclude Access rule on an endpoint; covered by SubmissionMarkControllerTest::testDraftMarkerSeesOnlyOwnMark. -->

- **GIVEN** j.devries has submitted a mark and a.bakker's mark on the same submission is still a draft
- **WHEN** a.bakker requests the marks of that submission from `GET /api/submissions/{id}/marks`
- **THEN** only a.bakker's own draft comes back

### Requirement: One person sets the final grade once every mark is in

When every allocated mark on a submission is `submitted`, the marking screen MUST show every mark side by side and a final grade field to an allocated marker and to users in `compliance-officers` or `team-leads`. The field MUST start at the average of the submitted grades when `finalGradeRule` is `average`, at the highest when it is `highest`, and empty when it is `manual`. Saving MUST run the existing save and return path, so the `Submission` gets the final grade, one `concept` `GradeEntry` is created and the submission is returned, and MUST record `finalGradeSetBy` and `finalGradeRuleApplied`. No code path MUST write the final grade without that save.

#### Scenario: Two markers agree a grade

- **GIVEN** a submission with marks of 7.5 from j.devries and 6.8 from a.bakker, both submitted, and the rule agreed by hand
- **WHEN** the coordinator opens the marking screen, reads both marks side by side, enters 7.2 and saves
- **THEN** the submission is returned to the learner with grade 7.2
- **AND** the gradebook holds one concept grade of 7.2 for that learner
- **AND** the submission records the coordinator as the person who set the final grade

#### Scenario: The average rule proposes a grade and waits for a person

- **GIVEN** the same two marks and the rule average
- **WHEN** the coordinator opens the marking screen
- **THEN** the final grade field shows 7.15
- **AND** nothing is saved or returned until the coordinator confirms

## Standards

Schema.org `CreativeWork` / `MediaObject` for submissions; IMS Caliper for submission events; QTI is *not* used here (that's `assessment`); plagiarism providers (Turnitin/Ouriginal/Compilatio) behind an interface.

## Data Model

All in OpenRegister. New: `Assignment`, `Submission`, `Rubric`. Touches: `GradeEntry` (from `grading`), `Material` (from `school-structure`). One ADR-031 PHP exception: the late-submission lifecycle guard. See `docs/ARCHITECTURE.md`.

## Out of Scope

- The structured-test / exam path (QTI items, scoring engine, proctoring) — that's the `assessment` spec.
- Peer review / peer grading (a follow-up).
- The actual plagiarism-detection algorithm (provider behind the hook only).
- Final-grade computation (the `grading` spec).
