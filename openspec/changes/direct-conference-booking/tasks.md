# Tasks: direct conference booking

## 1. Register
- [x] 1.1 `ConferenceRound.bookingMode`, `maxBookingsPerChild`, `create-free-slots`; `ConferenceSlot` states, transitions and fields; `ConferenceSignup` states and fields; register 0.34.30. Verify: payloads validated against the real fragments in the listener tests.

## 2. Free times and booking
- [x] 2.1 `ConferenceRoundBookingModeStamp`. Verify: PHPUnit `ConferenceRoundBookingModeStampTest`.
- [x] 2.2 `ConferenceFreeSlotGenerator`. Verify: PHPUnit `ConferenceFreeSlotGeneratorTest`.
- [x] 2.3 `ConferenceSlotBookingStamp` and `ConferenceSlotClaim`. Verify: PHPUnit `ConferenceSlotBookingStampTest` (cross-family refusal, two families at the same moment, one time per child).
- [x] 2.4 `ConferenceSlotBookingSync` and `ConferenceSlotTeacherGuard`. Verify: PHPUnit `ConferenceSlotBookingSyncTest`, `ConferenceSlotTeacherGuardTest`.
- [x] 2.5 `ConferenceScheduleGenerator` leaves a direct round alone. Verify: PHPUnit `ConferenceScheduleGeneratorTest::testADirectRoundIsNotPlanned`.

## 3. Portal and pages
- [x] 3.1 `parentConferenceFreeSlots`, `bookConferenceSlot`, `cancelConferenceTime`. Verify: PHPUnit `ParentConferenceDirectBookingTest`.
- [x] 3.2 "Bookings to answer" on the round page. Verify: `npm run check:manifest`.
- [x] 3.3 Dutch for every new string. Verify: `npm run check:schema-l10n`, `npm run check:l10n`.

## 4. Example data and live
- [x] 4.1 po set: group 7's parent evening books directly. Verify: `python3 scripts/example-sets/po.py --check`, `ExampleSetDescriptorContractTest`.
- [ ] 4.2 Live: the teacher creates free times, the guardian books one on the site, the teacher acknowledges, the guardian sees it, a second booking of the same time is refused. Verify: `tests/e2e/po-parent-flows.spec.ts` flow d.
