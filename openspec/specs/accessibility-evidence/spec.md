# accessibility-evidence Specification

## Purpose
TBD - created by archiving change governance-wcag-evidence-report. Update Purpose after archive.

## Requirements

### Requirement: Per criterion conformance record

The system MUST let an accessibility owner record for each WCAG 2.1 A and AA success criterion a result, the test method, an evidence reference and the test date. A `fail` MUST link to an `AccessibilityLimitation` for the same criterion, and a statement MUST NOT be published while a `fail` has no limitation.

#### Scenario: An owner records results

- **GIVEN** an accessibility owner on the conformance page
- **WHEN** the owner sets 1.4.3 to pass with the method axe and manual and a link to the report
- **THEN** the row shows pass with the date and the link

#### Scenario: A failure needs a limitation

- **GIVEN** a criterion set to fail with no linked limitation
- **WHEN** the owner publishes the statement
- **THEN** the publish is denied with the reason

#### Scenario: Untested criteria are visible

- **GIVEN** a statement with 12 criteria not tested
- **WHEN** the conformance table is read
- **THEN** those criteria show not tested and the summary counts them

### Requirement: Evidence on request

The public accessibility statement page MUST offer a download of the conformance table and statement fields as JSON and CSV without signing in. The export MUST contain no personal data.

#### Scenario: A procurement officer asks for evidence

- **GIVEN** an anonymous visitor on the public statement page
- **WHEN** the visitor chooses Download evidence as CSV
- **THEN** a CSV with one row per criterion, its result, method, evidence reference and date is downloaded
