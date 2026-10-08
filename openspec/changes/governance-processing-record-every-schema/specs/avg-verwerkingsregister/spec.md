## ADDED Requirements

### Requirement: Every schema that holds personal data declares its processing

Every schema in learniq's register that has a field naming or pointing at a person MUST carry an `x-openregister-processing` block with `code`, `naam`, `doelbinding`, `rechtsgrond`, `dataCategories` and `retentionReference`. The `code` MUST name a processing activity in learniq's processing catalogue.

#### Scenario: Grades appear in the record

- **GIVEN** the register is imported on an instance with OpenRegister's processing register
- **WHEN** the privacy officer exports the record of processing
- **THEN** the export has an activity for assessment and results that lists `GradeEntry`, `FinalGrade` and `ReportCard`

#### Scenario: An audit entry carries its purpose

- **GIVEN** a teacher publishes a grade entry
- **WHEN** the audit trail records the change
- **THEN** the entry carries the processing activity of `GradeEntry`

### Requirement: The new processing activities ship as drafts

The processing catalogue seed MUST contain each activity the register refers to, in state draft, so the privacy officer reviews and adopts it before it counts as the school's own.

#### Scenario: The privacy officer reviews a new activity

- **GIVEN** a fresh install
- **WHEN** the privacy officer opens the processing activities
- **THEN** "Care and support" is there as a draft with its purpose, legal basis and data categories filled in

### Requirement: The build fails on a personal-data schema without a processing block

The register check MUST fail when a schema has a property whose name is in the personal-data field list and no `x-openregister-processing` block, and MUST name the schema.

#### Scenario: A new schema without a block

- **GIVEN** a developer adds a schema `MentorMeeting` with a `learnerId` property and no processing block
- **WHEN** `npm run check:register` runs
- **THEN** it fails with "MentorMeeting holds personal data and declares no x-openregister-processing"
