## ADDED Requirements

### Requirement: The HR system owns a person's department, roles, manager and job title

A `LearnerProfile` MUST carry `jobTitle`, `hrSource` and `hrSyncedAt`. When `hrSource` is set, the system MUST refuse a manual change to `department`, `roles`, `managerId` or `jobTitle`, with a message that names the HR source. The HR feed's own writes MUST pass.

#### Scenario: hr edits an HR-fed department

- **GIVEN** a profile with `hrSource: afas`
- **WHEN** an hr user changes its `department` in learniq
- **THEN** the save is refused with a message that the department comes from AFAS

#### Scenario: A hand-made profile stays editable

- **GIVEN** a profile without `hrSource`
- **WHEN** an hr user changes its `department`
- **THEN** the save succeeds

### Requirement: A change of department, roles or job title re-runs the assignment for that person

When `department`, `roles` or `jobTitle` changes on a `LearnerProfile`, the system MUST check every published regulation against that profile. For each regulation that now covers the person and whose mandatory course has no open enrolment for them, it MUST create the enrolment with the regulation's deadline. A regulation's audience MAY also name job titles in `audienceJobTitles`.

#### Scenario: A new colleague joins the track maintenance department

- **GIVEN** a published regulation "Veilig werken langs het spoor" whose audience is department Spooronderhoud
- **WHEN** the HR feed creates a profile in Spooronderhoud
- **THEN** that person is enrolled in the regulation's mandatory course with its deadline

#### Scenario: Someone moves to a department in scope

- **GIVEN** the same regulation and a person in Facilitair without that enrolment
- **WHEN** the HR feed moves the person to Spooronderhoud
- **THEN** the person is enrolled in the mandatory course

#### Scenario: A replayed feed creates no duplicates

- **GIVEN** a person already enrolled through the regulation
- **WHEN** the HR feed writes the same department again
- **THEN** no second enrolment is created

### Requirement: Dropping out of scope is marked, never withdrawn

When a profile change means a regulation no longer covers a person with an open mandatory enrolment for it, the system MUST set `outOfScopeSince` on that enrolment and MUST NOT withdraw it. The compliance page MUST show these enrolments.

#### Scenario: Someone leaves the department

- **GIVEN** a person in Spooronderhoud with an open enrolment for "Veilig werken langs het spoor"
- **WHEN** the HR feed moves the person to Facilitair
- **THEN** the enrolment stays open with `outOfScopeSince` set to today
- **AND** the compliance officer sees it under "Niet meer in scope"
