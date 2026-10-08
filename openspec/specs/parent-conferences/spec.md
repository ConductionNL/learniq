# parent-conferences Specification

## Purpose
TBD - created by archiving change parent-evening-planner. Update Purpose after archive.

## Requirements

### Requirement: Persist parent-conferences domain objects in OpenRegister

The system MUST persist `ConferenceRound`, `TeacherAvailability`, `ConferenceSignup`,
`ConferenceSlot`, `ConferenceReport` as OpenRegister objects, each with an
`x-openregister-lifecycle` block (`ConferenceRound`: `draft → invitations-sent → booking-open →
booking-closed → scheduled → completed | cancelled`; `TeacherAvailability`: `draft → submitted →
locked`; `ConferenceSignup`: `draft → submitted → scheduled | waitlisted → cancelled`;
`ConferenceSlot`: `proposed → confirmed → completed | no-show | cancelled`; `ConferenceReport`:
`draft → recorded`). Every UUID foreign key MUST use the property-level relation dialect already in
use across the register (`format: uuid` + `$ref: <SchemaTitle>` on the property itself — no
separate `x-openregister-relations` block). `ConferenceReport` MUST NOT be `appendOnly`, because its
`record` transition is an update, which Open Register refuses on an append-only schema.

#### Scenario: All five schemas persist with their declared lifecycles

- **GIVEN** the parent-conferences schemas are registered
- **WHEN** a `ConferenceRound`, `TeacherAvailability`, `ConferenceSignup`, `ConferenceSlot`, and
  `ConferenceReport` are each created
- **THEN** each is stored as an OpenRegister object carrying its declared lifecycle state
- **AND** `ConferenceReport` is not `appendOnly`, so its `record` transition runs

### Requirement: A conference round declares its scope, slot duration, and buffer time

`ConferenceRound` MUST carry `cohortIds[]` (scope), `teacherIds[]` (eligible teachers),
`slotDurationMinutes` (default 10), `bufferMinutes` (walking-time gap between consecutive slots,
configurable per-round), and a booking window (`bookingOpensAt`/`bookingClosesAt`).

#### Scenario: A coordinator configures a round with a 10-minute slot and a 2-minute buffer

- **GIVEN** a coordinator creates a `ConferenceRound` for a report period
- **WHEN** they set `slotDurationMinutes: 10` and `bufferMinutes: 2` for three cohorts
- **THEN** the round persists that scope and timing, and every generated `ConferenceSlot` for the
  round is exactly 10 minutes long with at least 2 minutes before the next slot for the same
  teacher

### Requirement: Booking window auto-closes on schedule, not by a PHP TimedJob

The transition from `booking-open` to `booking-closed` MUST fire via a declared `scheduled`-type
`x-openregister-notifications` trigger keyed to `ConferenceRound.bookingClosesAt` — not a PHP
TimedJob — matching the `attendance` spec's "declared calculation trigger, not a TimedJob" posture.

#### Scenario: Booking closes automatically at the declared close time

- **GIVEN** a `ConferenceRound` in `booking-open` with `bookingClosesAt` in the past
- **WHEN** the declared `scheduled` trigger evaluates
- **THEN** the round transitions to `booking-closed` without any PHP TimedJob polling it

### Requirement: Digital invitations are a declared transition notification to the round's invited learners

Sending invitations MUST be the `ConferenceRound.send-invitations` transition (`draft →
invitations-sent`), computing `invitedLearnerIds[]` once from `cohortIds[]` → each `Cohort.learnerIds`
and persisting it onto the round, then declaring an `x-openregister-notifications` `transition`
rule with `recipients: [{kind: field, field: invitedLearnerIds}]` and an inline `subject` carrying
`nl` and `en` strings, per the verified dialect (`openspec/specs/scholiq-notifications/spec.md`).

#### Scenario: Every learner in the round's cohorts is invited

- **GIVEN** a `ConferenceRound` scoped to two cohorts totalling 40 learners
- **WHEN** the round transitions `draft → invitations-sent`
- **THEN** `invitedLearnerIds` contains exactly the 40 learners' NC user ids
- **AND** each receives an `nc-notification` per the declared `transition` rule

### Requirement: A guardian or self-signup submission is gated by a per-object authorization guard

`ConferenceSignup`'s `submit` transition (`draft → submitted`) MUST be gated by
`ConferenceSignupGuardianGuard`, which resolves the caller's NC user id server-side (never a
client-supplied claim) and passes only when the caller is listed in the target learner's
`LearnerProfile.parentIds`, or the caller **is** the target learner (18+ self-signup). A `draft`
`ConferenceSignup` MUST NOT be considered by the scheduling generator.

#### Scenario: A linked guardian can submit a signup for their own child

- **GIVEN** a guardian whose NC user id is in `LearnerProfile.parentIds` for learner L
- **WHEN** they submit a `ConferenceSignup` naming learner L
- **THEN** the `submit` transition succeeds and the signup moves to `submitted`

#### Scenario: An unrelated user cannot submit a signup for someone else's child

- **GIVEN** an authenticated user whose NC user id is NOT in `LearnerProfile.parentIds` for learner L
  and who is not learner L
- **WHEN** they attempt to submit a `ConferenceSignup` naming learner L
- **THEN** the `submit` transition is blocked by `ConferenceSignupGuardianGuard`
- **AND** the signup remains `draft`, inert to the scheduling generator

### Requirement: Schedule generation is a declared greedy solver triggered by a round transition, not a PHP CRUD controller

`ConferenceRound`'s `generate`/`regenerate` transitions MUST be observed by an OR-event-driven
handler (`ConferenceScheduleGenerator`, an ADR-031 "cross-object write bridge" exception, matching
`ExcuseApprovalHandler`'s shape) that reads `submitted` `ConferenceSignup`s and
`submitted`/`locked` `TeacherAvailability` for the round, runs the greedy earliest-fit
submission-order algorithm (design.md), and writes `ConferenceSlot` objects. The algorithm MUST
guarantee no two `ConferenceSlot`s for the same teacher overlap, and no two `ConferenceSlot`s for
the same signup overlap. `regenerate` MUST be idempotent: it MUST NOT re-shuffle `confirmed`
`ConferenceSlot`s, and MUST re-fill only from availability freed by cancelled signups or newly
submitted availability.

#### Scenario: Conflict-free generation from sign-ups and availability

- **GIVEN** a `ConferenceRound` with submitted `TeacherAvailability` for 3 teachers and 20 submitted
  `ConferenceSignup`s each requesting 1–3 of those teachers
- **WHEN** the round transitions to `generate`
- **THEN** every produced `ConferenceSlot` is conflict-free per teacher and per signup
- **AND** every signup with all requested teachers satisfied moves to `scheduled`
- **AND** every signup with an unmet teacher-request moves to `waitlisted`, naming which request
  could not be met

#### Scenario: Republish after a last-minute cancellation does not disturb confirmed slots

- **GIVEN** a `scheduled` round with some `confirmed` `ConferenceSlot`s and one `ConferenceSignup`
  that just moved to `cancelled`, freeing its teacher's slot
- **WHEN** the round transitions `regenerate`
- **THEN** all `confirmed` slots are unchanged
- **AND** the freed slot becomes available to any still-`waitlisted` signup requesting that teacher

### Requirement: A gespreksverslag is recorded to the pupil dossier

A `ConferenceReport` MUST be creatable against a `completed` `ConferenceSlot`, MUST move
`draft → recorded` (the schema is not `appendOnly`, which would refuse that move), and MUST carry `narrative`, `attendeeIds[]`, `recordedBy`,
`recordedAt`, and the learner reference — mirroring `LearningPlanEvaluation`'s shape
(`lib/Settings/scholiq_register.json` `learning-plan-evaluation`) so it becomes part of the
learner's queryable record set the same way `LearningPlan`/`GradeEntry`/`AttendanceRecord` already
do (per the OSO dossier composer's existing field list, `openspec/specs/data-exchange/spec.md:25`;
adding `ConferenceReport` to that composer is an explicit future follow-up, not this change).

#### Scenario: A teacher records a conversation report after a completed slot

- **GIVEN** a `ConferenceSlot` in `completed` status
- **WHEN** the teacher records a `ConferenceReport` with a narrative and attendees
- **THEN** the report persists linked to the slot and the learner
- **AND** it transitions `draft → recorded`, and the audit trail keeps each version
- **AND** a declared `transition` notification informs the learner it was recorded

### Requirement: Frontend is declarative with two named custom views

The frontend MUST be declarative: `src/manifest.json` index+detail pages for `ConferenceRound`,
`TeacherAvailability`, `ConferenceSlot`, `ConferenceReport`. The only custom Vue views MUST be
`BookConferenceSlotsView` (the guardian/self slot-picker — genuine calendar-grid UI a generic form
cannot render) and `ConferenceScheduleBoard` (the coordinator's manual-override board for resolving
`waitlisted` signups and triggering `regenerate`). No PHP CRUD controller MUST be introduced.

#### Scenario: Booking and coordinator resolution use the two named custom views only

- **GIVEN** the parent-conferences frontend is configured
- **WHEN** a guardian books slots and a coordinator resolves a waitlisted signup
- **THEN** the guardian's flow renders via `BookConferenceSlotsView` and the coordinator's via
  `ConferenceScheduleBoard`
- **AND** every other parent-conferences screen (round list/detail, availability list/detail, slot
  list/detail, report list/detail) is a declarative `src/manifest.json` page
- **AND** no PHP CRUD controller exists for any of the five schemas

### Requirement: A guardian without a Nextcloud account books from the parent portal
The `parent` portal contribution MUST list the conference rounds in `booking-open` that invited one of the guardian's children, the guardian's bookings, and the scheduled times for their children, and MUST offer the action `createConferenceSignup` with the round, the child and a note. A portal booking MUST be refused unless the child lists the guardian in `guardianRefs` and the round is `booking-open` and invited the child. An accepted portal booking MUST carry the child's `learnerId`, the guardian's `guardianRef`, the round's tenant, and the lifecycle `submitted`, so the scheduling generator considers it. When the guardian names no teacher the round offers, the booking MUST request the round's teachers of the child's group, or else all the round's teachers.

#### Scenario: A guardian books a conversation and sees the time
- **GIVEN** a round for Groep 7 in `booking-open`, and guardian Fatima Hulstkamp of Vera (Groep 7) signed in to the portal
- **WHEN** she books a conversation for Vera
- **AND** the school closes booking and generates the schedule
- **THEN** her conference times list a slot with Vera's group teacher
- @e2e tests/e2e/po-parent-flows.spec.ts

#### Scenario: A guardian cannot book for another child
- **GIVEN** the same guardian
- **WHEN** she books naming a child that does not list her
- **THEN** the booking is refused and nothing is stored
- @e2e exclude covered by PHPUnit `ConferenceSignupPortalStampTest::testABookingOutsideTheGuardiansChildrenOrOpenRoundsIsRefused` and portaliq's cross reference guard

### Requirement: Sending invitations fills the invited learners
The `send-invitations` transition MUST fill `invitedLearnerIds` (Nextcloud user ids) and `invitedLearnerRefs` (LearnerProfile uuids) from the round's cohorts, each learner once.

#### Scenario: A round for one group invites its pupils
- **GIVEN** a round for Groep 7
- **WHEN** it moves to `invitations-sent`
- **THEN** every pupil of Groep 7 is in `invitedLearnerIds` and `invitedLearnerRefs`
- @e2e exclude covered by PHPUnit `ConferenceInvitationActionTest`; exercised live by tests/e2e/po-parent-flows.spec.ts

### Requirement: The group teacher records the conversation report
Members of `instructors` MUST be able to create a `ConferenceReport`, and MUST be able to read and update only the reports whose `teacherId` is their own user id. Another teacher's reports stay closed to them, because a report is part of the pupil's dossier.

#### Scenario: The teacher records the gespreksverslag
- **GIVEN** a completed slot
- **WHEN** the group teacher records a report
- **THEN** the report is stored and moves to `recorded`
- @e2e tests/e2e/po-parent-flows.spec.ts

#### Scenario: A teacher cannot read another teacher's report
- **GIVEN** a report recorded by one group teacher
- **WHEN** a teacher of another group asks for it
- **THEN** it is not returned
- @e2e exclude covered by PHPUnit `ConferenceReportAuthorizationTest`

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

### Requirement: The guardian hears when the teacher answers a booking
The parent portal contribution MUST declare a change rule on the bookings collection (`parentConferenceSignups`, field `lifecycle`) that names its recipients by the learniq claim `guardianRef`, so the guardian who booked the time gets a message in the portal inbox when the teacher acknowledges or declines it. The acknowledgement MUST name the date and time and the teacher ("De leerkracht heeft uw gesprekstijd bevestigd: <datum tijd>, met <naam leerkracht>."). The decline MUST carry the teacher's note. Only the guardians whose portal account holds the booking's `guardianRef` and may read the booking MUST get it. Any other move of the booking MUST NOT be reported.

#### Scenario: The teacher acknowledges and Fatima reads it in her inbox
- **GIVEN** a free time Fatima booked for her child in the portal
- **WHEN** the teacher acknowledges it
- **THEN** Fatima's inbox ("Berichten") holds "De leerkracht heeft uw gesprekstijd bevestigd" with the date, the time and the teacher's name
- @e2e exclude Needs the portaliq change claim-addressed-change-notices on the same instance and a DigiD stub session; checked live on the primary-school instance (conference-answer-notice T02), and the rule's shape is pinned by PHPUnit `ParentConferenceDirectBookingTest::testTheGuardianHearsWhenTheTeacherAnswersABooking`; portaliq pins the delivery in `ClaimAddressedChangeNoticeTest`

#### Scenario: A decline tells the guardian why
- **GIVEN** a time Fatima booked
- **WHEN** the teacher declines it with a note
- **THEN** Fatima's message says the time does not go ahead and carries the note
- @e2e exclude covered by PHPUnit `ParentConferenceDirectBookingTest::testTheGuardianHearsWhenTheTeacherAnswersABooking` (the rule carries `{declineNote}`) and portaliq `ClaimAddressedChangeNoticeTest::testADeclineCarriesTheTeachersNote`

#### Scenario: Another family hears nothing
- **GIVEN** a booking of another family's guardian
- **WHEN** the teacher acknowledges it
- **THEN** Fatima gets no message
- @e2e exclude covered by portaliq `ClaimAddressedChangeNoticeTest::testAnAccountThatMayNotReadTheRecordIsNotTold`; the rule addresses the booking's own `guardianRef`

### Requirement: The teacher's conference screens read words, not codes
The conference rounds, conference slots and school calendar lists MUST declare their columns, and no column MUST show the tenant, a uuid reference or a Nextcloud user id. The school calendar MUST render its start and end as dates. The slot page MUST show its readable fields first (time, teacher, status) and MUST NOT show the tenant, the signup, the eligible pupils, the teacher's user id or the guardian reference. Every status these screens show MUST have a label, and every label and schema title they show MUST have a Dutch entry.

#### Scenario: A teacher reads the list of conference slots
@e2e exclude Manifest content; the list is nextcloud-vue's index page. Pinned by tests/unit-js/conferenceScreensReadWords.test.mjs; the live check on the primary-school instance is in the PR.
- **GIVEN** Meester Daan opens Conference slots
- **WHEN** the list renders
- **THEN** it shows time, teacher, status, start and location
- **AND** no tenant id, uuid or user id

#### Scenario: A round's status reads as a word
@e2e exclude Register content. Pinned by tests/unit-js/conferenceScreensReadWords.test.mjs "every status a conference screen shows has a label in Dutch".
- **GIVEN** a round whose lifecycle is `booking-closed`
- **WHEN** a teacher reads the list of rounds in Dutch
- **THEN** the status reads "Boeken gesloten"

#### Scenario: The school calendar reads dates
@e2e exclude Manifest content. Pinned by tests/unit-js/conferenceScreensReadWords.test.mjs "the school calendar reads its dates as dates".
- **GIVEN** a school event that starts at "2025-12-18T17:30:00+01:00"
- **WHEN** a coordinator opens the school calendar
- **THEN** the start reads as a date, not as the stored timestamp

### Requirement: The teacher availability list reads words
The teacher availability list MUST show the round by name, the teacher by display name, the free time blocks as days and times in the reader's language and time zone, and the status as a label. It MUST NOT show the round's uuid, the teacher's user id or the stored JSON of the blocks. The server MUST write the teacher's display name on the availability on every create and update, replacing a value a client sends, and an upgrade MUST write it on stored availabilities.

#### Scenario: A coordinator reads the teacher availability list
@e2e exclude Manifest content and a list formatter. Pinned by tests/unit-js/teacherAvailabilityReadsWords.test.mjs; the live check on the primary-school instance is in the PR.
- **GIVEN** Meester Daan submitted his availability for the autumn round, Thursday 15 October from 18:00 to 20:00
- **WHEN** a coordinator opens Teacher availability in Dutch
- **THEN** the row reads the round's name, "Meester Daan" and "do 15 okt, 18:00–20:00"
- **AND** no uuid, user id or JSON

#### Scenario: A sent teacher name is replaced
@e2e exclude Listener. Pinned by tests/Unit/Listener/ReadableCopyStampTest.php.
- **GIVEN** a client saves an availability for po-leerkracht-09 with teacherName "Iemand anders"
- **WHEN** the availability is stored
- **THEN** teacherName is the teacher's display name

### Requirement: The teacher availability page reads its times as words
The teacher availability detail page MUST show the free time blocks as days and times in the reader's language and time zone, through the same `timeBlocks` formatter as the list. It MUST NOT show the stored JSON or ISO timestamps of the blocks.

#### Scenario: A coordinator opens a teacher's availability
@e2e exclude Manifest content; the rendering is nextcloud-vue's data widget, pinned by its CnObjectDataWidgetFormatter spec. Pinned here by tests/unit-js/availabilityDetailReadsWords.test.mjs; the live check is in the PR.
- **GIVEN** Meester Daan is free on Thursday 22 October from 18:00 to 20:00
- **WHEN** a coordinator opens his availability in Dutch
- **THEN** the Times field reads "do 22 okt, 18:00–20:00"
- **AND** no timestamp or JSON
