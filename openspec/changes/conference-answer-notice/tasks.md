# Tasks: conference-answer-notice

- [x] **T01**: `ParentPortalCollections::conferenceAnsweredRule()` and the parent contribution's `notifications`. Verify: PHPUnit `ParentConferenceDirectBookingTest::testTheGuardianHearsWhenTheTeacherAnswersABooking`, `PortalContributionProviderTest::testParentManifestShape`; the manifest dump of all four audiences differs only by this rule.
- [ ] **T02**: Live check on the primary-school instance: Fatima books, the teacher acknowledges, Fatima's inbox shows the message.
- [x] **T03**: `openspec validate conference-answer-notice --strict`.
