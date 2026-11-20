## ADDED Requirements

### Requirement: A learner reads the feedback summary of their own submission with reviewers anonymised

The learner who handed in a Submission MUST be able to read its `PeerFeedbackSummary` once at least one `PeerReview` on it is `released`. The rule MUST be the schema's read authorization, so OpenRegister applies it before data is returned. Another learner MUST NOT read it. What the learner reads MUST NOT carry a reviewer's name, user id or `PeerReview` id. Staff read access MUST stay as it is.

#### Scenario: A learner opens the feedback on their work
- **GIVEN** learner Fenna's Submission with two released PeerReviews
- **WHEN** Fenna opens her submission
- **THEN** she reads one summary with the average score and the comments
- **AND** no reviewer name or id is in the answer

#### Scenario: Another learner's summary
- **GIVEN** learner Daan's Submission with a released PeerReview
- **WHEN** Fenna asks for its PeerFeedbackSummary
- **THEN** no summary is returned

#### Scenario: Nothing released yet
- **GIVEN** Fenna's Submission whose PeerReviews are all `assigned` or `submitted`
- **WHEN** Fenna opens her submission
- **THEN** no summary is shown
