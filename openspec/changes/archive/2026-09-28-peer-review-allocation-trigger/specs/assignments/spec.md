# assignments Specification

## ADDED Requirements

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
