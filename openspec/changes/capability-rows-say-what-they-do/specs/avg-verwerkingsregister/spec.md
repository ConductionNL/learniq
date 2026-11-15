## ADDED Requirements

### Requirement: A schema that is added or changed reaches the record of processing without anyone editing the record

The record of processing MUST be built from the `x-openregister-processing` blocks of learniq's schemas when the register is imported. A schema that is added or whose processing block changes MUST appear, or change, in the record on the next import. Nobody MUST have to edit the record by hand for it.

#### Scenario: A new schema shows up in the record

- **GIVEN** a release adds a schema `MentorMeeting` with a processing block naming the activity "Leerlingbegeleiding"
- **WHEN** the register is imported on an instance with OpenRegister's processing register
- **THEN** the exported record of processing lists `MentorMeeting` under "Leerlingbegeleiding"
- **AND** no person edited the record

#### Scenario: A changed data category shows up in the record

- **GIVEN** a release adds a health data category to the processing block of `SupportRequest`
- **WHEN** the register is imported again
- **THEN** the record's activity for `SupportRequest` lists the health data category
