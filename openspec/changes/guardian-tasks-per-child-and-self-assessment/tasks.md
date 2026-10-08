# Tasks: guardian-tasks-per-child-and-self-assessment

- [x] **T1**: `conference-invitation` schema, `ConferenceInvitations`, `ConferenceInvitationSync` (wired in `ConferenceListenerRegistrar`), `BackfillConferenceInvitations` (info.xml)
  - PHPUnit `ConferenceInvitationsTest`, `ConferenceInvitationSyncTest`
- [x] **T2**: po seeds through the generator, in step with the service
  - PHPUnit `ConferenceInvitationsTest::testTheSeededInvitationsAreWhatTheServiceWrites`
- [x] **T3**: `parentConferenceInvitations`, the overview task with `childName` and `titleTemplate`, the `parentPickATime` page, `titleTemplate` translated
  - PHPUnit `GuardianTasksAndSelfAssessmentTest`, `GuardianSitePagesTest`, `PortalLabelTranslatorTest`, `ParentRecordPageTest`
- [x] **T4**: `fillInSelfAssessment` on `studentWorkProcesses`
  - PHPUnit `GuardianTasksAndSelfAssessmentTest::testSheFillsInOnlyHerOwnEstimate`, `PortalRowActionConditionsTest`
- [x] **T5**: `studentSelfAssessment` and the "Volgende stap" button
  - PHPUnit `GuardianTasksAndSelfAssessmentTest::testTheNextStepOpensHerSelfAssessment`
- [x] **T6**: the warning in `NotificationRecipientGroupsAreDeclaredTest`
- [ ] **T7**: live: proof run 3
