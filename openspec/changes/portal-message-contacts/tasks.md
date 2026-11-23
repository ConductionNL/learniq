# Tasks: portal-message-contacts

- [x] **T1**: `PortalMessageContacts` (`childContacts`, `ownContacts`), primary teacher first, display names, role words
  - PHPUnit `PortalMessageContactsTest` over the real po and vo seeds and the portal declarations' display names
- [x] **T2**: provider methods `childMessageContacts`, `ownMessageContacts`; `contacts` on `parentChildren` and `studentEnrolments`; `composeLabel` and `composeHint` translated; Dutch strings
  - PHPUnit `PortalMessageContactsTest::testTheManifestsNameTheProvidersAndTheProviderAnswers`, `PortalContributionProviderTest`, `PortalLabelTranslatorTest`; `npm run check:l10n`
- [ ] **T3**: live check on the proof instance: Fatima writes to Meester Daan about Vera, Noor to Sanne Kramer (after portaliq `site-messages-per-record` lands)
- [x] **T4** (follow-up): a teacher's screen for these conversations
  - The pupil's page is that screen: its conversation section (`GuardianConversationService`, built in `learner-list-and-page-follow-the-boards` T5) reads, through portaliq's staff messaging, the threads the teacher takes part in about the pupil, and answers in the newest. It now also names the pupil's enrolments, so a thread the pupil started from her own enrolment (`ownContacts`) shows beside her guardians' threads (`childContacts`, keyed by her profile). PHPUnit `GuardianConversationServiceTest::testThePupilsOwnThreadFromHerEnrolmentShows`.
