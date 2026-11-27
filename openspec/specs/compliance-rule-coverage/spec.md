# compliance-rule-coverage Specification

## Purpose
Show per active regulation how many learners are in scope and covered, as a percentage with a red, amber or green state. Sort by percentage, filter by department and open the regulation from its row.

## Requirements

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

### Requirement: A compliance officer registers the rules that need mandatory training

The system SHALL keep each rule as a `Regulation` with a unique `slug` (capitals, digits, `-` and `_`), a `name`, an optional description and an audience: all employees, one or more departments, or one or more roles. A rule SHALL start as a draft and SHALL count in coverage only once published and active. Archiving a rule SHALL take it out of coverage and keep its history.

#### Scenario: A new rule is published

- **GIVEN** a compliance officer creates the rule `NIS2` "Cyberbeveiligingswet" for the board
- **WHEN** the officer publishes it
- **THEN** the rule appears on the compliance page with its own coverage row

#### Scenario: A draft rule does not count

- **GIVEN** a rule `BHV` in draft
- **WHEN** the compliance page is read
- **THEN** `BHV` has no coverage row and nobody is enrolled for it

### Requirement: Each rule has its own red and amber limits

A `Regulation` SHALL carry `ragRedThreshold` (default 70) and `ragAmberThreshold` (default 90) as coverage percentages. Its traffic light SHALL be red below the red limit, amber from the red limit up to the amber limit, and green at or above the amber limit. The compliance page and the rule page SHALL use the rule's own limits, never one fixed set.

#### Scenario: A strict rule turns amber sooner

- **GIVEN** rule `NIS2` with red at 90 and amber at 98, and rule `AVG` with the defaults
- **WHEN** both have 92% coverage
- **THEN** `NIS2` shows amber and `AVG` shows green

### Requirement: A rule says how often retraining is due

A `Regulation` SHALL carry `renewalCycleMonths` (default 12). A certificate issued on completing a course bound to the rule SHALL expire after that many months, unless the course sets a validity of its own. The expiry SHALL drive the existing expiry warnings and the re-enrolment on lapse.

#### Scenario: A rule with a two-year cycle

- **GIVEN** rule `BHV` with `renewalCycleMonths` 24 and its course without its own validity
- **WHEN** a learner completes the course on 1 March 2026
- **THEN** the certificate expires on 1 March 2028

#### Scenario: The course's own validity wins

- **GIVEN** the same rule and a course that sets a validity of 365 days
- **WHEN** a learner completes it on 1 March 2026
- **THEN** the certificate expires on 1 March 2027

### Requirement: A course is bound to the rule it trains for

A `Course` SHALL name at most one rule in `regulationSlug`, and SHALL be marked `mandatoryTraining` when it is the mandatory training for that rule. Publishing a rule SHALL enrol every learner in its audience in each published course bound to it, with the rule's deadline, unless the learner already holds a live enrolment for that course. Enrolments, attestations and certificates from a bound course SHALL carry the rule's slug, so coverage counts them for that rule.

#### Scenario: Publishing a rule enrols its audience

- **GIVEN** the course "NIS2 bewustzijn" bound to rule `NIS2`, whose audience is the board
- **WHEN** the rule is published
- **THEN** each board member has a mandatory enrolment in the course for `NIS2`

#### Scenario: An unbound course does not count

- **GIVEN** a course with no `regulationSlug`
- **WHEN** a learner completes it
- **THEN** no rule's coverage changes

### Requirement: Coverage rolls up from team to department to directorate

A learner's `department` SHALL be read as a path whose levels are separated by `/`, for example `Operations/Logistiek/Team Noord`. The roll-up SHALL show one row per level that has learners, with learners, obligations, covered, excused, upcoming deadlines, overdue and a coverage percentage. A level's figures SHALL include every level under it. Only users the action matrix allows for `compliance.department-rollup` SHALL read it, and it SHALL count only the caller's tenant.

#### Scenario: A directorate sees its departments added up

- **GIVEN** 10 obligations in `Operations/Logistiek` with 8 covered, and 10 in `Operations/Inkoop` with 5 covered
- **WHEN** the compliance officer chooses `Operations` on the obligations page (learniq/LqCfVerplichtingen)
- **THEN** the header shows 20 obligations, 13 covered and 65%, and the page lists each obligation one by one
- **AND** choosing `Operations/Logistiek` narrows the list to that department with its own figures

#### Scenario: A learner may not read the roll-up

- **GIVEN** a learner outside the groups allowed for `compliance.department-rollup`
- **WHEN** the learner requests the roll-up
- **THEN** the server answers 403

### Requirement: Learners are reminded before, managers are told after the deadline

For a mandatory `Enrolment` with a `dueDate`, the system SHALL notify the learner once a day from three days before the due date until it is complete. Once the due date has passed and the enrolment is not complete, it SHALL notify the learner's manager (`managerId`) and the `hr` group once a day. Both SHALL be declared notification rules on the `Enrolment` schema, delivered by OpenRegister, and each reader MAY switch them off.

#### Scenario: Three days before the deadline

- **GIVEN** a mandatory enrolment due on 10 March
- **WHEN** the daily check runs on 7 March
- **THEN** the learner gets "Your mandatory course is due soon"

#### Scenario: Past the deadline

- **GIVEN** the same enrolment, still not complete on 11 March
- **WHEN** the daily check runs
- **THEN** the learner's manager and the hr group get "An employee's mandatory course is overdue"
- **AND** the learner's own reminder stops

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
