## ADDED Requirements

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
Members of `instructors` MUST be able to read, create and update a `ConferenceReport`.

#### Scenario: The teacher records the gespreksverslag
- **GIVEN** a completed slot
- **WHEN** the group teacher records a report
- **THEN** the report is stored and moves to `recorded`
- @e2e tests/e2e/po-parent-flows.spec.ts
