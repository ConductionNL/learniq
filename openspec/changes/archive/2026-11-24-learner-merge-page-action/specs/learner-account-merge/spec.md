## ADDED Requirements

### Requirement: An administrator merges a duplicate account from the learner's page

`LearnerProfileDetail` MUST offer "Merge into another account" to users in `hr` and `compliance-officers` on a profile in state `active`, and to nobody else. The action MUST ask for the surviving profile through a picker limited to other active profiles, MUST ask for confirmation naming both profiles, and MUST then run the `merge` transition with `mergedInto` set to the chosen profile. The page MUST NOT offer `delete`.

#### Scenario: Merge two accounts of one pupil

- **GIVEN** two active profiles of one pupil with no open enrolment in the same course, and a user in `hr`
- **WHEN** the user opens the duplicate, chooses "Merge into another account", picks the other profile and confirms
- **THEN** the duplicate is `merged` with `mergedInto` set to the chosen profile
- **AND** the duplicate's enrolments belong to the chosen profile

#### Scenario: A teacher does not see the action

- **GIVEN** a user in `instructors` only
- **WHEN** the user opens an active profile
- **THEN** the header offers no merge

#### Scenario: The picker leaves out the profile itself and inactive ones

- **GIVEN** an active profile A, an active profile B and a merged profile C
- **WHEN** a user in `hr` starts the merge on A
- **THEN** the picker offers B and offers neither A nor C

### Requirement: A refused merge says why and changes nothing

When `LearnerMergeGuard` refuses, the page MUST show the guard's reason and both profiles MUST stay as they were.

#### Scenario: Two open seats in one course

- **GIVEN** two active profiles that both hold an open enrolment in one course
- **WHEN** a user in `hr` merges one into the other
- **THEN** the page shows the refusal reason naming the course
- **AND** both profiles stay `active` with their own enrolments

### Requirement: A merged profile points to the surviving one

A profile in state `merged` MUST show "Merged into" with a link to the surviving profile, and MUST offer no lifecycle action.

#### Scenario: Open an old account

- **GIVEN** a profile merged into another
- **WHEN** a user in `hr` opens it
- **THEN** the page shows "Merged into" with the survivor's name as a link
