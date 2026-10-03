## ADDED Requirements

### Requirement: Per rule coverage table

The compliance page MUST show one row for each active regulation with the number of learners in scope, the number covered, the covered percentage and a red, amber or green state computed from the regulation's `ragRedThreshold` and `ragAmberThreshold`. The table MUST be sortable by percentage and MUST be filterable by department. A row MUST link to the regulation.

#### Scenario: An officer compares all rules

- **GIVEN** a compliance officer on /compliance with four active regulations
- **WHEN** the officer opens the page
- **THEN** a table lists four rows with in scope, covered, percent and a RAG state each

#### Scenario: A rule with nobody in scope

- **GIVEN** an active regulation whose audience matches no learner
- **WHEN** the officer opens the page
- **THEN** the row shows zero in scope and no percentage or RAG state

#### Scenario: The department filter narrows the table

- **GIVEN** a table of four rules
- **WHEN** the officer filters to the department Logistics
- **THEN** every figure counts only learners of Logistics

### Requirement: Access

The by-regulation figures MUST be available only to users who may already read the department roll-up, and MUST count only learners of the caller's tenant.

#### Scenario: A learner cannot read the table

- **GIVEN** a learner
- **WHEN** the learner requests the by-regulation figures
- **THEN** the server answers 403
