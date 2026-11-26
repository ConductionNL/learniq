## ADDED Requirements

### Requirement: A trainer's unfinished assessment is kept apart from the assessments

The trainer's `createWerkprocesAssessment` action MUST declare `draftSchema` naming learniq's `werkproces-assessment-draft` schema, so the portal keeps her unfinished answers there as an OpenRegister draft of hers. That schema MUST hold every field the action asks and the `practicalTrainerId` the portal stamps, and MUST require none of them. The action MUST name its save button `draftLabel` "Keep for later" ("Bewaren voor later", as the board draws it). Learniq MUST NOT read or act on that schema: only the finished assessment, sent through learniq's endpoint, becomes a `werkproces-assessment`.

#### Scenario: Saved halfway, nothing reaches the school
- **GIVEN** a trainer fills in the first step of an assessment and saves it for later
- **WHEN** the school's instructor lists the werkproces assessments
- **THEN** the unfinished assessment is not among them
- **AND** the trainer finds it again when she opens the form
- @e2e tests/e2e/mbo-trainer-flows.spec.ts
