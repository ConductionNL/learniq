## ADDED Requirements

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
