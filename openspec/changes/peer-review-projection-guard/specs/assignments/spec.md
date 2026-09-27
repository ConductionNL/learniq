# assignments Specification

## ADDED Requirements

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

## REMOVED Requirements

### Requirement: Reviewer identity is hidden from the submission author via a server-enforced feedback projection
**Reason**: Its last scenario declared the reviewee side of double-blind "UI-level only". This change makes
that side server-enforced on the reviewer's path, and a MODIFIED block cannot drop a scenario whose title
states the opposite.
**Migration**: Replaced by "Both sides of peer review anonymity are server-enforced projections", which
keeps the first three scenarios word for word and replaces the fourth.
