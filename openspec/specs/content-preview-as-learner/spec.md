# content-preview-as-learner Specification

## Purpose
TBD - created by archiving change content-adaptive-next-step-and-preview. Update Purpose after archive.

## Requirements

### Requirement: Preview as learner

The system MUST let a course author open the course in a preview mode that renders lessons in the learner player, applies release conditions and next step rules for a simulated result the author chooses, and MUST NOT create a lesson completion, an assessment result, an xAPI statement or an audit entry of learner activity. The preview MUST show a banner that says it is a preview.

#### Scenario: A teacher walks the course as a learner

- **GIVEN** an author on a course page
- **WHEN** the author chooses Preview as learner and picks the simulated result 45
- **THEN** the player opens with a preview banner and the next step follows the rules for 45

#### Scenario: A preview leaves no records

- **GIVEN** a preview session in which the author completed three lessons
- **WHEN** the author reads the course completion data
- **THEN** no completion, result or statement exists from that session

#### Scenario: A learner cannot preview

- **GIVEN** a learner without an author role
- **WHEN** the learner opens the preview route
- **THEN** the server answers 403
