## ADDED Requirements

### Requirement: Regulation exemption records

The system MUST let a manager request, and a compliance officer grant or reject, an exemption of one learner from one regulation. A grant MUST carry a rationale and a policy reference, the deciding user and a validity period, and MUST fail closed without them. An exemption MUST NOT be granted by the person who requested it.

#### Scenario: A manager requests an exemption

- **GIVEN** a manager on the Exemptions page
- **WHEN** the manager requests an exemption for Jan de Vries from Working at height with the reason Medical
- **THEN** a request is stored in state requested and the compliance officers are notified

#### Scenario: An officer grants it with a rationale

- **GIVEN** a requested exemption
- **WHEN** a compliance officer grants it with a rationale and a policy reference and valid until 2027-06-30
- **THEN** the exemption is granted and shows the officer and the period

#### Scenario: Grant without a rationale is refused

- **GIVEN** a requested exemption
- **WHEN** a compliance officer grants it with an empty rationale
- **THEN** the transition is denied with the reason shown

#### Scenario: A requester cannot grant their own request

- **GIVEN** an exemption requested by a compliance officer
- **WHEN** the same officer grants it
- **THEN** the transition is denied

### Requirement: Exemptions in the roll up

A granted exemption within its validity period MUST remove that learner from the regulation's obligations in `ComplianceRollupService` and MUST be counted separately as excused. An expired exemption MUST stop having that effect on its valid until date.

#### Scenario: An exempt learner is not a gap

- **GIVEN** a regulation with ten learners in scope of whom one holds a granted exemption and six are covered
- **WHEN** the compliance page is read
- **THEN** the table shows nine in scope, six covered and one excused

#### Scenario: The exemption lapses

- **GIVEN** a granted exemption with valid until yesterday
- **WHEN** the roll up is read today
- **THEN** the learner is in scope again and is not covered
