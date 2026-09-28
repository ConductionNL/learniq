# Course management

## ADDED Requirements

### Requirement: A course leaves the school only through the sharing gate
The system MUST offer a share export, separate from the regular export, that refuses with the full list of reasons unless all of these hold: the course has a `license` that is an open Creative Commons licence or CC0; the course has an `author`; no lesson has its own non-open `license`; no material has a non-empty `license` outside the open set; the exporting user confirmed `noPupilData`; the exporting user confirmed `rightsCleared`. A lesson without its own licence MUST be judged by the course licence.

#### Scenario: A course with no licence is refused
- **GIVEN** a course with an author and no `license`
- **WHEN** a teacher requests a share export with both confirmations
- **THEN** the response is 422 and `blockers` holds `licence-missing`

#### Scenario: All rights reserved is refused
- **GIVEN** a course with `license: "all-rights-reserved"`
- **WHEN** a share export is requested
- **THEN** `blockers` holds `licence-not-open`

#### Scenario: A publisher-licensed material blocks the course
- **GIVEN** an openly licensed course with one material whose `license` is `© Uitgeverij Voorbeeld`
- **WHEN** a share export is requested
- **THEN** `blockers` holds `material-licence-not-open` naming that material

#### Scenario: Missing confirmations are named
- **GIVEN** an openly licensed course with an author
- **WHEN** a share export is requested without `noPupilData` and without `rightsCleared`
- **THEN** `blockers` holds `pupil-data-not-confirmed` and `rights-not-confirmed`, and no consent record is written

### Requirement: A share package carries no school-bound or personal fields
The share package MUST be learniq JSON without `@self`, `tenant_id`, material `fileRef`, `sessionId`, `cohortId`, `curriculumPlanId`, `curriculumPlanComponentId`, `programmeIds`, `gradeEntryComponentId`, `gradeScaleId` or an assessment's `accessCode`, and with an empty LTI placement list. It MUST add a `sharing` block with the licence, author, subject, education levels, language, goals covered and the share date, and MUST NOT name the Nextcloud user who confirmed.

#### Scenario: An assessment access code does not travel
- **GIVEN** an assessment with `accessCode: "KLAS3B"` and `cohortId` set
- **WHEN** its course is share-exported
- **THEN** the package's assessment has neither `accessCode` nor `cohortId`

### Requirement: Every share export leaves a consent record
A successful share export MUST write a `CourseShareConsent` with the course, the course name, purpose `download`, the confirming user, the time, both confirmations and the licence. If the record cannot be written, the export MUST fail. `CourseShareConsent` MUST be readable by `instructors`, `team-leads`, `coordinators`, `administration-managers`, `compliance-officers` and the confirming user, creatable by the four course-authoring groups, and updatable by `administration-managers` only.

#### Scenario: A school leader sees who shared what
- **GIVEN** teacher `docent-07` share-exported course "Nederlands havo 4"
- **WHEN** a user in `administration-managers` lists course share consents
- **THEN** a record shows `docent-07`, the time, both confirmations and `CC-BY-SA-4.0`

### Requirement: The export page offers sharing with the confirmations
The course package export page MUST offer a "Share outside the school" switch that shows the two confirmations, sends the share request, and lists the refusal reasons in the user's language.

#### Scenario: A teacher sees why sharing is refused
- **GIVEN** the switch is on and the course has no author
- **WHEN** the teacher submits
- **THEN** the page lists "Name the author on the course, so others can credit them."
