## ADDED Requirements

### Requirement: A course day that has passed is no longer coming

A company booking, and each participant's course day on it, MUST stop being coming the day after its last course day, within an hour. A participant's next course day MUST be the first coming one from today. A booking MUST stay coming on each of its course days.

#### Scenario: Tom on the Friday after his exam day
- **GIVEN** Tom's F-gassen day was Thursday 8 October and his next course starts on 3 November
- **WHEN** he opens Mijn academie on Friday 9 October
- **THEN** "Uw volgende cursusdag" is 3 November and the F-gassen day is not listed as coming
- @e2e exclude covered by PHPUnit `PastCourseDaysJobTest::testYesterdaysCourseDayIsNoLongerComing`

#### Scenario: The second day of a two-day course
- **GIVEN** a course on Tuesday 20 and Wednesday 21 October
- **WHEN** it is Wednesday 21 October
- **THEN** the booking is still coming
- @e2e exclude covered by PHPUnit `EmployerBookingFactsTest::testABookingStopsBeingComingAfterItsLastDay`
