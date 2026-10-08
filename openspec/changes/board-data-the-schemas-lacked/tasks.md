# Tasks: board-data-the-schemas-lacked

- [x] **T1**: `cohort.capacity`; `CoursePlaces`; the course row's note
  - PHPUnit `PortalPublicIndexTest`, `BoardDataSchemaAdditionsTest::testTheAcademyCohortsCarryAFittingCapacity`
- [x] **T2**: placement agreements (schema, seed, labels, "Afspraken")
  - PHPUnit `BoardDataSchemaAdditionsTest::testMilansPlacementAndWorkProcessesFitTheirSchemas`, `PortalContributionProviderTest::testThePlacementShowsItsAgreementsAndWorkProcesses`
- [x] **T3**: `werkproces-progress` (schema, seed, `studentWorkProcesses`, the table on the placement page)
  - PHPUnit as T2, `BoardDataSchemaAdditionsTest::testTheNewPropertiesAreOptional`
- [x] **T3b**: portaliq round 5 declarations: the child's name on each absence report (lookup by `learnerRef`); "Daarna" with `skip: 1`, `limit: 4`; the "Volgende stap" card (steps `display: highlight`) with the current step's date and visit narrative from the provider; "Je begeleiders" from `studentTrainers` (forward join on her placements) and `studentSchoolCoaches` (reverse join on the coach's user id)
  - PHPUnit `GuardianSitePagesTest`, `ParticipantSitePagesTest::testTheNextCourseDayShowsOnce`, `BpvPlacementStepsTest`, `PortalContributionProviderTest`
- [ ] **T3c**: the guardian's task title with the child's name: a conference round names groups (`cohortIds`), never one child, so a lookup by row field has no field to key on; needs a per-child row or a list-aware lookup
- [ ] **T4**: "Nu invullen": the student's own update of her estimate
- [ ] **T5**: live: proof run 3
