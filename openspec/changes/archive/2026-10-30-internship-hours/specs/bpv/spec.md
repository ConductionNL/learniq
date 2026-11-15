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

### Requirement: The hours bar shows approved, waiting and returned hours

A placement MUST carry `hoursWaitingTotal` (the hours submitted on its weeks that are still `submitted`) and `hoursReturnedTotal` (the hours submitted on its `rejected` weeks less what was approved of them) next to `hoursApprovedTotal`, recomputed by the server whenever one of its weeks is written and written only when one of the three moved. The student's hours page MUST open with a `kpi` block with `display: segmented` over her placement: approved, waiting and sent back, against `agreedHours`. The school MUST have its own list of weeks of hours (`/bpv/hours`) and the placement's detail page MUST list its weeks. Design of record: school-design `esdoornveen/preview/MijnLijst.png` ("96 uur goedgekeurd, 16 uur wacht, 8 uur teruggestuurd, nog 360 uur").

#### Scenario: Milan's bar reads the board's numbers
- GIVEN Milan de Groot's placement with weeks 36 to 39 approved at 24 hours, 16 hours of week 40 waiting and 8 hours of week 40 sent back
- WHEN a week of his is written
- THEN the placement holds 96 approved, 16 waiting and 8 returned hours, against 480 agreed
- @e2e exclude server rollup, covered by PHPUnit `HourWeekListenersTest::testWaitingAndReturnedHoursAreKeptBesideTheApprovedOnes`; the bar is checked by `tests/e2e/portal-design/esdoornveen.spec.ts`
