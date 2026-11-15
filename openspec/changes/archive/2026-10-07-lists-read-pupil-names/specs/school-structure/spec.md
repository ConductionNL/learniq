## ADDED Requirements

### Requirement: Every staff list reads a pupil by name
A staff list that shows the pupil of a row MUST show the pupil's learner profile by name. It MUST resolve the profile through the row's `learnerRef` when the row carries one, and otherwise by the row's Nextcloud user id (`ncUserId` on the learner profile). It MUST NOT show the Nextcloud user id of a pupil that has a profile, and MUST NOT show an empty cell for a pupil that has none: that pupil keeps the user id. Each profile MUST be fetched at most once per page load.

#### Scenario: A teacher reads a group's enrolments
@e2e exclude Manifest content and a cell widget whose lookup is a pure module. Pinned by tests/unit-js/listsReadPupilNames.test.mjs; the live check on the primary-school instance is in the PR.
- **GIVEN** Vera Hulstkamp (po-leerling-147) is enrolled in Groep 7
- **WHEN** her teacher opens Groep 7 and reads the enrolments
- **THEN** the row names the pupil "Vera Hulstkamp"
- **AND** not `po-leerling-147`

#### Scenario: A list over a schema without learnerRef reads the name
@e2e exclude Pure module. Pinned by tests/unit-js/listsReadPupilNames.test.mjs.
- **GIVEN** an attendance signal for po-leerling-139, a schema that stores only the user id
- **WHEN** a coordinator opens the attendance threshold's signals
- **THEN** the row names the pupil whose profile has `ncUserId` po-leerling-139

#### Scenario: An enrolment made without learnerRef still reads the name
@e2e exclude Pure module. Pinned by tests/unit-js/listsReadPupilNames.test.mjs.
- **GIVEN** an enrolment created through the form, which stores `learnerId` but no `learnerRef`
- **WHEN** the teacher reads the course's enrolments
- **THEN** the row names the pupil, looked up by user id

#### Scenario: A pupil without a profile keeps the user id
@e2e exclude Pure module. Pinned by tests/unit-js/listsReadPupilNames.test.mjs.
- **GIVEN** a grade for a user id no learner profile carries
- **WHEN** the teacher reads the grades
- **THEN** the cell shows the user id, not an empty cell
