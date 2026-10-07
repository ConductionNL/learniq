# Tasks: portal-parent-child-record

- [x] **T1**: register 0.34.31: `school-event`, `ReportPeriod.schoolId`, `Assignment.learnerRefs`
  - PHPUnit `ParentRecordPageTest::testTheRowsPassTheRealSchemas`
- [x] **T2**: `AssignmentLearnerRefsStamp` fills `learnerRefs` from the group's enrolments, wired on create and update
  - PHPUnit `AssignmentLearnerRefsStampTest`
- [x] **T3**: `ParentRecordPage`: the record page, the calendar page, the default page of every other section, and five collections
  - PHPUnit `ParentRecordPageTest`, `PortalContributionProviderTest`
- [x] **T4**: Dutch for every visible string; the translator covers units, source kinds and titles, lookup labels
  - PHPUnit `PortalLabelTranslatorTest`
- [x] **T5**: the "School calendar" staff page (`src/manifest.d/school-calendar.json`)
  - `npm run check:manifest`, `npm run check:menu-role-gates`
- [x] **T6**: the po example set: school events, report periods with their school, homework and submissions
  - `python3 scripts/example-sets/po.py --check`, PHPUnit `ExampleSetDescriptorContractTest`
- [x] **T7**: e2e: Fatima opens Vera, reads the figures and the calendar
  - `tests/e2e/po-parent-flows.spec.ts`
