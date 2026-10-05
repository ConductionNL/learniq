## ADDED Requirements

### Requirement: An index page on a schema with a pupil user id declares its columns
An index page whose schema holds a pupil's Nextcloud user id (`learnerId`, `learnerIds`, `affectedLearnerIds`, `checkedLearnerId`) MUST declare its columns, and MUST show that field through the `learnerName` cell. It MUST NOT show `learnerUserId` or `accusedLearnerUserId`. Where the schema stores the learner profile uuid in `learnerId` or `accusedLearnerId`, the column MUST resolve it to the profile's name. An index page MUST NOT show the tenant id.

#### Scenario: A coordinator reads the attendance flags
@e2e exclude Manifest content. Pinned by tests/unit-js/remainingListsReadPupilNames.test.mjs; the live check on the primary-school instance is in the PR.
- **GIVEN** an attendance flag for po-leerling-139
- **WHEN** a coordinator opens the attendance flags
- **THEN** the first column is headed "Leerling" and names the pupil
- **AND** the list shows no `po-leerling-139`, no tenant id and the group by name

#### Scenario: The exam accommodations and the BSA flags
@e2e exclude Manifest content. Pinned by tests/unit-js/remainingListsReadPupilNames.test.mjs.
- **GIVEN** an exam accommodation and a BSA flag, each for a pupil with a learner profile
- **WHEN** a coordinator opens either list
- **THEN** the first column names the pupil
- **AND** the assessment and the programme read by name

#### Scenario: A list that only gains the name keeps its columns
@e2e exclude Manifest content. Pinned by tests/unit-js/remainingListsReadPupilNames.test.mjs.
- **GIVEN** the dossier notes index page, which showed every property of its schema
- **WHEN** a mentor opens it
- **THEN** the pupil reads by name
- **AND** every other column it showed is still there, except the tenant id

#### Scenario: A new index page without columns fails the build
@e2e exclude Guard test. Pinned by tests/unit-js/remainingListsReadPupilNames.test.mjs ("an index page on a schema with a pupil user id declares its columns").
- **GIVEN** a new index page on a schema with `learnerId` and no `columns`
- **WHEN** the unit tests run
- **THEN** they fail and name the page
