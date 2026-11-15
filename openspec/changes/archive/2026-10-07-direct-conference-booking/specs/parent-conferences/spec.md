## ADDED Requirements

### Requirement: A conference round books directly or by preference
A `ConferenceRound` MUST carry `bookingMode`, `direct` or `preference`. A new round without a value MUST get `direct` when the instance runs as a primary school (segment `po`) and `preference` otherwise. A round without a value MUST behave as `preference`, so a round stored before this requirement keeps its flow. A round with direct booking MUST NOT be planned by the scheduling generator.

#### Scenario: A primary school's new round books directly
- **GIVEN** an instance running as a primary school
- **WHEN** a teacher creates a conference round without a booking mode
- **THEN** the round's `bookingMode` is `direct`
- @e2e tests/e2e/po-parent-flows.spec.ts

#### Scenario: An old round keeps the preference flow
- **GIVEN** a round stored without `bookingMode`
- **WHEN** it moves to `scheduled`
- **THEN** the scheduling generator plans its signups as before
- @e2e exclude covered by PHPUnit `ConferenceRoundBookingModeStampTest::testAnOldRoundWithoutAModeKeepsThePreferenceFlow` and `ConferenceScheduleGeneratorTest`

### Requirement: Teachers publish free times from their availability
When a round with direct booking moves to `booking-open` (`open-booking`, or `create-free-slots` once open), every submitted or locked `TeacherAvailability` of the round MUST be cut into `free` slots of the round's `slotDurationMinutes` with `bufferMinutes` between them. A free slot MUST carry the pupils who may book it (`eligibleLearnerRefs`: the invited pupils of the teacher's own groups, or every invited pupil when the teacher has none in the round), the teacher's name and a label in the school's time zone. A time that overlaps a slot the teacher already holds in the round MUST be skipped.

#### Scenario: The teacher creates free times
- **GIVEN** a direct round for Groep 7 and the group teacher's availability from 18:00 to 20:00
- **WHEN** booking opens
- **THEN** free slots of 10 minutes with 2 minutes between them exist for the group's pupils
- @e2e tests/e2e/po-parent-flows.spec.ts

#### Scenario: Running it again adds only what is missing
- **GIVEN** free slots that already exist
- **WHEN** the teacher runs `create-free-slots`
- **THEN** no time is written twice
- @e2e exclude covered by PHPUnit `ConferenceFreeSlotGeneratorTest::testRunningAgainAddsOnlyWhatIsMissing`

### Requirement: A parent claims one free time, atomically
A portal booking of a free time MUST be refused unless the child lists the guardian in `guardianRefs`, the round uses direct booking, is `booking-open`, invited the child and its window has not closed, and the slot is `free` and offered to the child. The check and the write MUST run under exclusive locks on the slot and on the child in the round, so two families never book the same time. A child MUST NOT hold more booked times in a round than `maxBookingsPerChild` (one when empty). An accepted booking MUST set the slot to `booked` with the child, the guardian and the booking, and the booking to `booked` with the time and the teacher.

#### Scenario: A guardian books a free time
- **GIVEN** guardian Fatima Hulstkamp of Vera (Groep 7) signed in to the portal and a free time with Vera's teacher
- **WHEN** she books it
- **THEN** her conference times show the time as booked
- @e2e tests/e2e/po-parent-flows.spec.ts

#### Scenario: A second booking of the same time is refused
- **GIVEN** a time another booking already took
- **WHEN** a guardian books it
- **THEN** the booking is refused and the time stays with the first booking
- @e2e tests/e2e/po-parent-flows.spec.ts

#### Scenario: A guardian cannot book for another family's child
- **GIVEN** a child that does not list the guardian
- **WHEN** she books a time naming that child
- **THEN** the booking is refused and nothing is written
- @e2e exclude covered by PHPUnit `ConferenceSlotBookingStampTest::testAGuardianCannotBookForAnotherFamilysChild` and portaliq's cross reference guard

#### Scenario: Two families at the same moment
- **GIVEN** two guardians booking the same free time at the same moment
- **WHEN** the second request arrives while the first is between its check and its write
- **THEN** the second is refused and only the first booking is written
- @e2e exclude a race cannot be timed from a browser; covered by PHPUnit `ConferenceSlotBookingStampTest::testTwoFamiliesAtTheSameMomentNeverBothGetTheTime`

### Requirement: The teacher acknowledges or declines a booked time
Only the slot's teacher, or a member of `admin`, `coordinators`, `team-leads` or `administration-managers`, MUST be able to `acknowledge` (`booked` to `acknowledged`) or `decline` (`booked` or `acknowledged` to `declined`, with a required `declineNote`). The booking MUST follow the slot, carrying the note on a decline. A declined or cancelled time MUST be offered again as a new free slot while the round is `booking-open`. A parent MUST be able to cancel a time they booked until the booking window closes, and not after.

#### Scenario: The teacher acknowledges and the parent sees it
- **GIVEN** a time Fatima booked
- **WHEN** the teacher acknowledges it
- **THEN** Fatima's conference times show it as acknowledged
- @e2e tests/e2e/po-parent-flows.spec.ts

#### Scenario: The teacher declines with a note
- **GIVEN** a booked time
- **WHEN** the teacher declines it with a note
- **THEN** the booking is declined with that note, and the same time is free again
- @e2e exclude covered by PHPUnit `ConferenceSlotBookingSyncTest::testADeclinedTimeTellsTheParentWhyAndIsFreeAgain`

#### Scenario: Another teacher cannot answer the booking
- **GIVEN** a time booked with one teacher
- **WHEN** another teacher acknowledges it
- **THEN** the transition is refused
- @e2e exclude covered by PHPUnit `ConferenceSlotTeacherGuardTest`

#### Scenario: A late cancel is refused
- **GIVEN** a round whose booking window has closed
- **WHEN** the parent cancels her time in the portal
- **THEN** the cancel is refused
- @e2e exclude covered by PHPUnit `ConferenceSlotBookingSyncTest::testAPortalCancelAfterTheWindowIsRefused`
