## ADDED Requirements

### Requirement: A week of BPV hours is a record of its own

A student's realised BPV hours MUST be stored per ISO week on a `BpvHourWeek` record that names its placement, and MUST NOT be accumulated onto `BpvPlacement`. The record MUST keep the hours the student submitted and the hours the trainer approved as two values, so a correction is readable afterwards, and MUST name who submitted and who approved, with the moment of each.

#### Scenario: A student submits a week
- GIVEN Daan worked 32 hours in week 39 of his own placement
- WHEN he submits that week from his portal
- THEN a `BpvHourWeek` is stored with `hoursSubmitted: 32`, `submittedBy` his own profile and `lifecycle: submitted`
- @e2e tests/e2e/pupil-flows.spec.ts

#### Scenario: The trainer approves it unchanged
- GIVEN that week is waiting for Karin
- WHEN she approves it
- THEN `hoursApproved` is 32, `approvedBy` is her praktijkopleider record, `approvedAt` is set and `lifecycle` is `approved`
- @e2e tests/e2e/trainer-flows.spec.ts

#### Scenario: The trainer corrects it, and both numbers stay
- GIVEN Daan submitted 32 hours and left two hours early on the Thursday
- WHEN Karin approves 30 with a note
- THEN `hoursSubmitted` is still 32, `hoursApproved` is 30, `lifecycle` is `corrected` and the note is stored
- @e2e exclude a correction is asserted in the service's unit tests; the e2e covers the plain approval

#### Scenario: A week of another trainer's student is refused
- GIVEN a placement whose praktijkopleider is somebody else
- WHEN Karin approves a week of it
- THEN the write is refused and nothing is stored
- @e2e exclude covered from the caller in the endpoint's unit tests

### Requirement: Hours are shown against the hours that were agreed

A placement MAY state `agreedHours`. Where it does, the portal and learniq's own screens MUST show hours done against that total; where it does not, they MUST show the hours done alone and MUST NOT invent a total or a percentage.

#### Scenario: A placement with an agreed total
- GIVEN Daan's placement agreed 640 hours and 312 are approved
- WHEN Karin opens her overview
- THEN his card reads 312 of 640
- @e2e tests/e2e/trainer-flows.spec.ts

#### Scenario: A placement without one
- GIVEN Lotte's placement states no agreed hours
- WHEN Karin opens her overview
- THEN her card names the hours approved and shows no progress bar
- @e2e exclude asserted on the page declaration, which is where the decision lives
