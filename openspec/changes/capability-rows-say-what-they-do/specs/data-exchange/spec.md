## ADDED Requirements

### Requirement: A user requests an export to a chosen target from the data exchange page

A user who holds `exchange.request` MUST be able to choose a target (`bron-rod`, `oso`, `leerplicht`, `swv`, `uwlr`, `edu-v`, `basispoort` or `hr`) and, where it applies, a learner or a cohort on the data exchange request page, and send the request. Learniq MUST ask integriq for an export job for that target with the target's mapping, and MUST answer with the job id. The jobs page MUST list the job. A user without the right, or an unknown target, MUST be refused.

#### Scenario: An administrator sends pupil data to ROD

- **GIVEN** integriq is installed with the ROD target configured, and an administrator who holds `exchange.request`
- **WHEN** they open the data exchange request page, choose `bron-rod` and a cohort, and send
- **THEN** integriq receives an export job for `bron-rod` with mapping `learniq-bron-rod-export-learner`
- **AND** the jobs page lists the job

#### Scenario: A user without the right

- **GIVEN** a teacher the action matrix does not grant `exchange.request`
- **WHEN** they send an export request
- **THEN** the request is refused with 403 and no job is asked for

#### Scenario: Integriq is not installed

- **GIVEN** integriq is not installed
- **WHEN** an administrator sends an export request
- **THEN** the request answers 503 with a message that integriq is needed, and no job is stored
