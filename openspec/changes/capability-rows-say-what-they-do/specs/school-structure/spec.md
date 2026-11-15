## ADDED Requirements

### Requirement: A learner picks and submits their electives from the picker

In the subject choice picker, a learner MUST be able to pick a curriculum plan, an academic year and their electives, and submit them. Submitting MUST save the `SubjectChoice` and run its `submit` transition in the learner's own name, after which the choice is checked against the plan's elective rules.

#### Scenario: A learner submits a valid choice

- **GIVEN** learner Sem and a curriculum plan that asks for exactly two electives
- **WHEN** Sem picks the plan, the year and two electives and chooses "Submit subject choice"
- **THEN** a `SubjectChoice` for Sem is submitted and then moves to `validated`
- **AND** the picker says the choice was submitted for review

#### Scenario: A learner submits too few electives

- **GIVEN** the same plan
- **WHEN** Sem submits a choice with one elective
- **THEN** the choice moves to `needs-revision` with an error naming the minimum

### Requirement: A minor's own subject choice waits for a guardian's consent

When a learner under 18 submits their own subject choice, the system MUST hold it until a linked guardian gives consent. The choice MUST NOT be validated or approved before that consent is recorded, with the guardian named in `guardianConsentBy`.

#### Scenario: A 15-year-old submits their own choice

- **GIVEN** learner Noor, aged 15, with a linked guardian
- **WHEN** Noor submits her subject choice
- **THEN** the choice waits for consent and is not validated yet

#### Scenario: The guardian consents

- **GIVEN** Noor's choice waiting for consent
- **WHEN** her linked guardian gives consent
- **THEN** `guardianConsentGiven` is true with the guardian as `guardianConsentBy`
- **AND** the choice is checked against the plan's elective rules
