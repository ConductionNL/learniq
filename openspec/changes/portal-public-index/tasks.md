# Tasks: portal-public-index

- [x] **T1**: `PortalPublicIndex` (courses with runs, programmes, school-wide days; example-set namespace)
  - PHPUnit `PortalPublicIndexTest` over the real po, vo, mbo and training seeds together
- [x] **T2**: `getPublicIndex` on the provider; Dutch words
  - PHPUnit `PortalPublicIndexTest::testTheProviderAnswersThroughTheService`; `npm run check:l10n`
- [x] **T3**: the example portals place `nlCatalogue` and the academy's home list fills itself
  - PHPUnit `ExamplePortalDeclarationsTest`
- [ ] **T4**: live check next to the Zoeken boards on the proof instance (after portaliq `portal-public-catalogue`)
- [x] **T5**: the test schedule in the index (`exam-sitting` of public exam periods: day, time, subject, room, department and year; filters); register: `exam-period.public`, `exam-period.bringList` (optional, additive)
  - PHPUnit `PortalPublicIndexTest::testAPublicTestWeekIsInTheIndexWithoutNames`
- [x] **T6**: a category on every school-day item from `school-event.kind`; Dutch words
  - `school-event.kind` gains `holiday`, `day-off` and `parent-evening`; items carry `category` and `categoryLabel` (PHPUnit `PortalPublicIndexTest::testEverySchoolDayCarriesItsCategory`)
- [x] **T7**: vo example set: toetsweek 1 public, with the 4 havo sittings of the Editor board; po: the year's holidays and study days
  - po half done: herfst- and kerstvakantie added, the Ouderavond is a `parent-evening`
  - vo half: toetsweek 1 (9 to 13 November 2026) published and public with what to bring, and four 4 havo papers in the aula (Nederlands, wiskunde A on Tuesday 10 November 08.30 to 10.10, Engels, economie), written last by `add_public_test_week` so no earlier uuid moves

