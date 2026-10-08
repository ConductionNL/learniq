# Tasks: portal-public-index

- [x] **T1**: `PortalPublicIndex` (courses with runs, programmes, school-wide days; example-set namespace)
  - PHPUnit `PortalPublicIndexTest` over the real po, vo, mbo and training seeds together
- [x] **T2**: `getPublicIndex` on the provider; Dutch words
  - PHPUnit `PortalPublicIndexTest::testTheProviderAnswersThroughTheService`; `npm run check:l10n`
- [x] **T3**: the example portals place `nlCatalogue` and the academy's home list fills itself
  - PHPUnit `ExamplePortalDeclarationsTest`
- [ ] **T4**: live check next to the Zoeken boards on the proof instance (after portaliq `portal-public-catalogue`)
