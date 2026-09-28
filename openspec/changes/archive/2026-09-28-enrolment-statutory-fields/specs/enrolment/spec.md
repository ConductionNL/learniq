# Enrolment Specification

## ADDED Requirements

### Requirement: Enrolment carries inschrijving date, volgnummer and its own vestiging
`Enrolment` MUST declare `inschrijvingDate` (nullable date), `volgnummer` (nullable integer) and `locationId` (nullable `$ref Vestiging`) additively. `Enrolment.locationId` is the inschrijving's own vestiging and is independent of any `Cohort.locationId` the pupil is later grouped into (school-and-location-records).

#### Scenario: An inschrijving records its date, volgnummer and vestiging
- **GIVEN** an `Enrolment` representing a school inschrijving
- **WHEN** `inschrijvingDate`, `volgnummer` and `locationId` are set
- **THEN** all three persist on the `Enrolment` object, independent of the `Cohort` it may later reference

#### Scenario: A pre-existing Enrolment without these fields is unaffected
- **GIVEN** a pre-existing `Enrolment` row with none of the three fields set
- **WHEN** it is read
- **THEN** each resolves to `null` and the existing `learnerId`/`courseId`/`source`/lifecycle fields are unchanged

### Requirement: Enrolment carries a destination school on withdrawal
`Enrolment` MUST declare `destinationSchoolId` (nullable `$ref School`) additively, captured alongside the existing `withdraw` transition and free-text `reason` field.

#### Scenario: A leaver's destination school is recorded on withdrawal
- **GIVEN** an active `Enrolment` for a groep-8 leaver
- **WHEN** the `withdraw` transition fires with `reason` set and `destinationSchoolId` set to the receiving school
- **THEN** both persist on the withdrawn `Enrolment` object

### Requirement: Enrolment carries leerjaar per pupil, independent of the cohort name
`Enrolment` MUST declare `leerjaar` (nullable integer, 1 to 8) additively. A combination group (e.g. `Groep 5/6`) is one `Cohort` whose member `Enrolment`s carry different `leerjaar` values; `leerjaar` MUST NOT be parsed from the `Cohort.name` string.

#### Scenario: A combination group carries two leerjaar values across its enrolments
- **GIVEN** a `Cohort` named "Groep 5/6" with two `Enrolment`s referencing it via `cohortId`
- **WHEN** one `Enrolment.leerjaar` is set to 5 and the other to 6
- **THEN** both values persist independently on their own `Enrolment` objects, and neither is derived from the `Cohort`'s `name`

### Requirement: CohortDetail's roster surfaces leerjaar
The `CohortDetail` page's enrolment roster widget MUST include a `leerjaar` column.

#### Scenario: A coordinator sees each pupil's leerjaar on the group roster
- **GIVEN** `CohortDetail` for a combination group
- **WHEN** the roster widget renders
- **THEN** each row shows that enrolment's `leerjaar` value alongside the existing learner/course columns
