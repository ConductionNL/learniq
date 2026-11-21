## ADDED Requirements

### Requirement: A learner hands in a file from their own Nextcloud files

On the hand-in screen, a learner MUST be able to pick the file for their submission from their own Nextcloud files. The picked file MUST be attached to the submission as an OpenRegister file attachment.

#### Scenario: A learner picks a report from their drive

- **GIVEN** a learner with `verslag.docx` in their Nextcloud files and an open assignment
- **WHEN** the learner opens the hand-in screen, picks `verslag.docx` from their files and hands in
- **THEN** the submission lands in `submitted` with `verslag.docx` attached

### Requirement: A hand-in after the deadline is refused when late work is not accepted

`SubmissionWindowGuard` MUST refuse both `submit` and `submitLate` after the deadline of an assignment that does not allow late submission. The refusal MUST say that the deadline has passed and late work is not accepted.

#### Scenario: A learner tries to hand in after the deadline

- **GIVEN** an assignment whose deadline has passed and that does not accept late work
- **WHEN** the learner tries to hand in their draft
- **THEN** the hand-in is refused with "The deadline has passed and this assignment does not accept late work."
- **AND** the submission stays `draft`

### Requirement: A teacher's mark is not released while a rubric criterion is unscored

When an assignment has a rubric, the system MUST refuse to release a teacher's mark on a submission while any criterion of that rubric has no score. The refusal MUST name the unscored criteria.

#### Scenario: A teacher forgets one criterion

- **GIVEN** a submission for an assignment whose rubric has "Structuur" and "Argumentatie"
- **WHEN** the teacher scores only "Structuur" and tries to return the marked work
- **THEN** the release is refused and names "Argumentatie" as unscored

#### Scenario: Every criterion is scored

- **GIVEN** the same submission with both criteria scored
- **WHEN** the teacher returns the marked work
- **THEN** the mark is released
