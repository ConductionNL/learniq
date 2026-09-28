# Example Sets Specification

## ADDED Requirements

### Requirement: A second example set does not duplicate a regulation code
Before a shipped example set is imported, the app MUST leave out each row of the `regulation` bucket whose code (`slug`) already exists in the learniq register under a different uuid. A row whose code exists under its own uuid MUST be kept. When the existing rows cannot be read, every row MUST be kept and the import MUST go on.

#### Scenario: The training set after the company set
- **GIVEN** the company set is loaded, with its own VCA and NIS2 rows
- **WHEN** the training set is loaded
- **THEN** its VCA and NIS2 rows are left out, its other regulations are imported, and no other bucket changes

#### Scenario: Loading the same set again
- **GIVEN** the training set is loaded
- **WHEN** it is loaded again
- **THEN** every one of its regulation rows is imported, so a changed row is updated
