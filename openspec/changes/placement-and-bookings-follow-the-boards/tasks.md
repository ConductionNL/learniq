# Tasks: placement-and-bookings-follow-the-boards

- [x] **T1**: placement labels and states, hours bar on the placement page, one date per step
  - PHPUnit `PortalContributionProviderTest::testThePlacementReadsInWordsAndShowsTheHours`, `BpvPlacementStepsTest`
- [x] **T2**: booking participants as rows; certificates grouped per certificate
  - PHPUnit `EmployerSitePagesTest::testABookingListsItsParticipantsAndCertificatesGroup`
- [ ] **T3**: next-step card, werkprocessen, begeleiders, afspraken (need schema or portaliq; see proposal)
- [x] **T4**: academy course rows: link and meta (weekdays, days, kind); places left have no source (no capacity field)
  - PHPUnit `PortalPublicIndexTest`
- [x] **T5**: steps as bars; lead photo placeholders; "Voor leerbedrijven" as a card of links; the aside without `portal`
  - PHPUnit `PortalContributionProviderTest::testThePlacementReadsInWordsAndShowsTheHours`, `ExamplePortalDeclarationsTest::testTheHomesMarkTheLeadPhotoAndInviteCompaniesWithLinks`, `testTheSchoolHeroesHoldTheirAside`
