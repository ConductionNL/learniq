## ADDED Requirements

### Requirement: A learning plan starts from a template for its kind

A `LearningPlanTemplate` MUST carry a `kind` (`opp`, `handelingsplan`, `iep`, `pdp`, `idp` or `generic`), its sections, goal domains, required signer roles and a default review cadence. Only an `active` template MUST be offered. A plan made from a template MUST carry its `templateId` and the template's kind.

#### Scenario: A coordinator starts a plan from a template

- **GIVEN** an active template of kind `opp` with three sections
- **WHEN** a coordinator creates a learning plan for a learner from that template
- **THEN** the plan names the template, has kind `opp` and opens with the three sections

### Requirement: A learning plan is evaluated on its agreed date and the outcome is recorded

A `LearningPlanEvaluation` MUST record the plan, the date and who evaluated, the outcome per goal, a narrative and the next review date. Its `record` transition (`draft` to `recorded`) MUST apply the goal outcomes to the plan's goals.

#### Scenario: A coordinator records the evaluation

- **GIVEN** an active plan with two goals and a review date of today
- **WHEN** the coordinator records an evaluation with "achieved" for the first goal and "partly achieved" for the second
- **THEN** the evaluation is `recorded` and the plan's goals carry those outcomes
