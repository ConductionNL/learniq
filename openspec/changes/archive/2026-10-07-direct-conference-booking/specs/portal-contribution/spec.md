## ADDED Requirements

### Requirement: The parent audience books and cancels a free conference time
The `parent` contribution MUST list the free times of direct rounds for the guardian's children (`parentConferenceFreeSlots`, `conference-slot` in `free`, scoped by `eligibleLearnerRefs` through the child join, REQ-PCON-004/005), before `parentConferenceSlots`, because portaliq fills the time picker from the first collection over `conference-slot`. It MUST offer `bookConferenceSlot` (create `conference-signup` with `learnerRef`, `slotId` and `notes`, the child checked against the guardian's own children) and `cancelConferenceTime` (update `conference-slot`, scoped by `guardianRef`, the server setting `lifecycle` to `cancelled`) as a row action on the conference times. `parentConferenceSlots` MUST keep its field names and add `conferenceRoundId`, `teacherName`, `slotLabel` and `declineNote`.

#### Scenario: The guardian books a time and sees the teacher's answer
- **GIVEN** guardian Fatima Hulstkamp signed in to the portal and a direct round with free times for Vera
- **WHEN** she books one and the teacher acknowledges it
- **THEN** her conference times show the time as acknowledged
- @e2e tests/e2e/po-parent-flows.spec.ts

#### Scenario: Free times name no child
- **GIVEN** the free times collection
- **WHEN** a guardian reads it
- **THEN** no row carries a child reference
- @e2e exclude covered by PHPUnit `ParentConferenceDirectBookingTest::testFreeTimesAreTheChildrensOwnAndFeedThePicker`
