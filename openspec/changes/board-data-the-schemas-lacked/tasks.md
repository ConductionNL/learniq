# Tasks: board-data-the-schemas-lacked

- [x] **T1**: `cohort.capacity`; `CoursePlaces`; the course row's note
  - PHPUnit `PortalPublicIndexTest`, `BoardDataSchemaAdditionsTest::testTheAcademyCohortsCarryAFittingCapacity`
- [x] **T2**: placement agreements (schema, seed, labels, "Afspraken")
  - PHPUnit `BoardDataSchemaAdditionsTest::testMilansPlacementAndWorkProcessesFitTheirSchemas`, `PortalContributionProviderTest::testThePlacementShowsItsAgreementsAndWorkProcesses`
- [x] **T3**: `werkproces-progress` (schema, seed, `studentWorkProcesses`, the table on the placement page)
  - PHPUnit as T2, `BoardDataSchemaAdditionsTest::testTheNewPropertiesAreOptional`
- [ ] **T4**: "Nu invullen": the student's own update of her estimate
- [ ] **T5**: live: proof run 3
