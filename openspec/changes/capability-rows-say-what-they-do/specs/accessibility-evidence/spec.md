## ADDED Requirements

### Requirement: Learniq's own conformance record shows every WCAG 2.1 A and AA criterion as passing

Learniq MUST ship a conformance record for its own screens with a result for every WCAG 2.1 level A and AA criterion. Each criterion MUST read `pass` or `not-applicable`, with the method and an evidence reference, so the published statement can say the product is fully compliant.

#### Scenario: A procurement officer reads learniq's conformance

- **GIVEN** a fresh learniq install
- **WHEN** a procurement officer opens the conformance table
- **THEN** every level A and AA criterion reads `pass` or `not-applicable` with its method, evidence reference and date
- **AND** no criterion reads `fail` or `not-tested`
