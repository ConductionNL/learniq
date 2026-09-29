# student-analytics Specification

## ADDED Requirements

### Requirement: A planner forecasts next year's learners per programme year and subject

A user in `team-leads` or `compliance-officers` MUST be able to keep forecast scenarios for a target school year, with a progression rate per programme and programme year (up, repeat, leave, adding up to one) and an expected intake, and MUST be able to compute from them, the current cohorts, the placed applications and the approved subject choices the number of learners per programme year and per subject next year. Figures derived from a share of choices made so far MUST be marked as estimated. Computing MUST NOT change any cohort, enrolment or choice.

#### Scenario: A deputy head forecasts havo 4

- **GIVEN** 118 learners in havo 3 this year and a scenario with rates 0.88 up, 0.07 repeat and 0.05 leave for havo 3
- **WHEN** the deputy head opens Reports, "Enrolment forecast", picks the scenario and computes
- **THEN** havo 4 next year shows 104 learners from havo 3 plus the repeaters of havo 4

#### Scenario: Rates that do not add up are refused

<!-- @e2e exclude Service validation; covered by EnrolmentForecastServiceTest::testRatesMustAddUpToOne. -->

- **GIVEN** a scenario whose rates for vwo 5 add up to 1.1
- **WHEN** it is computed
- **THEN** the computation is refused with a reason naming vwo 5

### Requirement: The forecast says how many groups are needed

The computed forecast MUST show per programme year and per subject the number of groups needed at the scenario's target group size, rounded up.

#### Scenario: Groups for an elective subject

- **GIVEN** a forecast of 61 learners taking "Economie" in havo 4 and a target group size of 28
- **WHEN** the planner reads the subject table
- **THEN** "Economie" in havo 4 needs 3 groups
