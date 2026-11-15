# Tasks: participant-portal

- [x] **T1**: enrolment copies `firstDay`, `dayLabel`, `timeLabel`, `placeLabel`, `trainerName`, `upcoming` written by the booking projection; seeded
  - PHPUnit `EmployerBookingFactsTest::testTheSeededBookingsAgreeWithTheServer`, `ParticipantSitePagesTest::testTomsCourseDaysAreOnHisEnrolments`
- [x] **T2**: `ParticipantSitePages` and the `participant` audience
  - PHPUnit `ParticipantSitePagesTest`, `PortalContributionProviderTest`, `PortalLabelTranslatorTest`; portaliq `PortalManifestNormaliser` drops nothing
- [x] **T3**: Dutch for the labels and schema strings
- [ ] **T4**: e2e: Tom's overview on the proof instance (`tests/e2e/portal-design/warmtepompacademie.spec.ts`)
