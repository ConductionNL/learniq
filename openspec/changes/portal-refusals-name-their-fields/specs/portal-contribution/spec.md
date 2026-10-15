## ADDED Requirements

### Requirement: A portal endpoint refusal names the fields to change

A learniq portal endpoint that refuses a form's answers with 422 MUST name each refused field: an answer left empty in `errors` as `{field: ''}`, and an answer of the wrong kind in `invalid` as `{field: kind}` (`integer`, `range`, `date`). It MUST keep its `error` code and status, and MUST name only the form's own fields, never a claim. An endpoint action whose answers portaliq should shape MUST declare the schema they belong to: `approveHourWeek` declares `bpv-hour-week`, so `hoursApproved` is a number.

#### Scenario: Petra sends a week of hours without choosing the week
- GIVEN the trainer's form "Approve the hours of a week"
- WHEN it reaches learniq without `hourWeekId`
- THEN the answer is 422 `{error: 'incomplete', errors: {hourWeekId: ''}}`
- @e2e exclude endpoint body; covered by tests/Unit/Service/Portal/PortalHourWeekApprovalTest.php (testAnIncompleteOrUnknownWeekIsRefused)

#### Scenario: Linda books too many places and types a birth date the wrong way round
- GIVEN the employer's forms "Book places" and "Fill in a birth date"
- WHEN she asks for 13 places, and gives 14-03-1994 as a birth date
- THEN the first answer is 422 with `invalid: {participantCount: 'range'}` and the second with `invalid: {birthDate: 'date'}`
- AND an empty form names every empty field in `errors`
- @e2e exclude endpoint body; covered by tests/Unit/Service/Portal/PortalEmployerBookingsTest.php (testARefusalNamesTheFieldsToChange) and tests/Unit/Service/Portal/PortalAnswersTest.php

#### Scenario: The hours are a number
- GIVEN the trainer's action `approveHourWeek`
- WHEN portaliq reads it
- THEN it declares the schema `bpv-hour-week`, whose `hoursApproved` is a number
- @e2e exclude manifest shape; covered by tests/Unit/Portal/PortalContributionProviderTest.php
