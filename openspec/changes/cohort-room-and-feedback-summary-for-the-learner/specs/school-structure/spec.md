## ADDED Requirements

### Requirement: A new cohort gets its own Talk room automatically

When a Cohort becomes active and has no Talk conversation linked, Learniq MUST create a Talk conversation named after the cohort and link it to the Cohort through OpenRegister's Talk link. The cohort's active members MUST be its participants, as the membership sync already does for a linked room. A cohort that already has a linked conversation MUST NOT get a second one. When Talk is not available, nothing MUST be created and the coordinator can link a room by hand later.

#### Scenario: A coordinator activates a new cohort
- **GIVEN** a Cohort "Verpleegkunde 2027" with no Talk conversation linked and Talk enabled
- **WHEN** the cohort becomes active
- **THEN** a Talk conversation named "Verpleegkunde 2027" exists and is linked to the cohort
- **AND** the Cohort detail page shows it as the cohort's class space

#### Scenario: A cohort that already has a room
- **GIVEN** an active Cohort with a linked Talk conversation
- **WHEN** the cohort is saved again
- **THEN** no new conversation is created

#### Scenario: Talk is not available
- **GIVEN** Talk is not installed or not enabled
- **WHEN** a cohort becomes active
- **THEN** no conversation is created and the cohort saves normally
