# Tasks: portal-message-contacts

- [x] **T1**: `PortalMessageContacts` (`childContacts`, `ownContacts`), primary teacher first, display names, role words
  - PHPUnit `PortalMessageContactsTest` over the real po and vo seeds and the portal declarations' display names
- [x] **T2**: provider methods `childMessageContacts`, `ownMessageContacts`; `contacts` on `parentChildren` and `studentEnrolments`; `composeLabel` and `composeHint` translated; Dutch strings
  - PHPUnit `PortalMessageContactsTest::testTheManifestsNameTheProvidersAndTheProviderAnswers`, `PortalContributionProviderTest`, `PortalLabelTranslatorTest`; `npm run check:l10n`
- [ ] **T3**: live check on the proof instance: Fatima writes to Meester Daan about Vera, Noor to Sanne Kramer (after portaliq `site-messages-per-record` lands)
- [ ] **T4** (follow-up): a teacher's screen for these conversations
