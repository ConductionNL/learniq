## ADDED Requirements

### Requirement: The assignment is previewed per audience

The system MUST answer, for a published regulation and a caller with the `regulation.assign` action, the regulation's course name and the audiences its learners fall into per kind (section, team, HR function). With a chosen audience it MUST also answer the number of learners in it, how many are already enrolled in every course of the regulation, how many would be enrolled, and the first 50 learners with their outcome. The preview MUST NOT write anything. The action MUST be checked before the regulation is read.

#### Scenario: Preview the language section
- **GIVEN** the published regulation Meldcode with course "Meldcode in de praktijk (MC-2026)" and three staff in section Talen, one of them already enrolled
- **WHEN** the compliance officer previews the assignment for section Talen
- **THEN** the answer says 3 in total, 1 already enrolled and 2 to enrol, with a row per staff member
- **AND** no enrolment is created

#### Scenario: A caller without the action
- **GIVEN** a teacher without `regulation.assign`
- **WHEN** they ask for the preview
- **THEN** the answer is 403 and the regulation is not read

### Requirement: An assignment can be narrowed to one audience

When the assignment names an `audienceKind` (section, team or hr-function) and an `audience`, the system MUST enrol only the learners of the regulation's audience that belong to it, with the given `dueDate` when one is sent. Without an `audienceKind` it MUST enrol the whole audience. An unknown kind, an empty audience or a malformed due date MUST be refused with 400 before anything is written.

#### Scenario: Enrol one team
- **GIVEN** the published regulation Meldcode, team Talen/Frans holding Nadia (enrolled) and Tom
- **WHEN** the compliance officer assigns it to team Talen/Frans with due date 2026-12-01
- **THEN** Tom gets a mandatory enrolment due 2026-12-01 and nobody else is enrolled
