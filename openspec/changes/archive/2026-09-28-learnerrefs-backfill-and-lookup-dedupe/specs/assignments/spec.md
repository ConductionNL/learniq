# Assignments: submission learnerRefs back-fill delta

## ADDED Requirements

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
