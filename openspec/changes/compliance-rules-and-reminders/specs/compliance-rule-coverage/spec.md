# compliance-rule-coverage Specification (delta)

## ADDED Requirements

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
- **WHEN** the compliance officer opens the department roll-up
- **THEN** `Operations` shows 20 obligations, 13 covered and 65%
- **AND** each department shows its own figures one level below

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
