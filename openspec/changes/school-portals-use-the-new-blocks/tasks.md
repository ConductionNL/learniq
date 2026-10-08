# Tasks: school-portals-use-the-new-blocks

> Archive pass 2026-10-07: code done; open: T6 (live check: po-parent-flows.spec.ts test h not run live).

- [x] **T1**: chrome keys from lane L1's fixtures in the four declarations; line-icon names; `audienceLabel` on public news; `accountLabel` and `residentMenu` fillable
  - PHPUnit `ExamplePortalDeclarationsTest`, `ExamplePortalProvisionerTest`
- [x] **T2**: `LearnerProfile.groupLabel` (readable copy, enrolment cascade, deferred restamp, backfill, seeded in the sets)
  - PHPUnit `LearnerGroupLabelTest`, `ReadableCopyStampTest`, `BackfillReadableCopiesTest`, the example-set tests
- [x] **T3**: guardian pages on the merged keys (greeting, highlight button, cards and switcher subline, child page in "Mijn kinderen", badge, page tile, limit and sort, inbox per child, status tones, summary, confirmation)
  - PHPUnit `GuardianSitePagesTest`, `ParentRecordPageTest`, `PortalLabelTranslatorTest`
- [x] **T4**: grade notices carry `learnerRef` and `courseName`; `parentGradeInbox`; `visibleFromField` on the inboxes
  - PHPUnit `GradeRollupHandlerTest`, `PortalContributionProviderTest`
- [x] **T5**: `report-subject-grade` rows on publish, `parentReportSubjectGrades`, bars on the child page, seeded in the po set
  - PHPUnit `ReportSubjectGradeRowsTest`, `GuardianSitePagesTest`, `ExampleSetDescriptorContractTest`
- [x] **T6**: e2e: the guardian switches child, reads the task and reports Vera sick from the overview
  - `tests/e2e/po-parent-flows.spec.ts` (h); not run live in this lane
