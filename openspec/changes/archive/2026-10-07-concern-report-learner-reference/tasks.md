# Tasks: a concern report may name the pupil it is about

- [x] 1.1 Amend the requirement so a concern report may reference the learner profile through the optional `learnerId`, and nothing else. Verify: PHPUnit `ConcernReportRegisterTest::testTheReportIsStructurallyIsolated`.
- [x] 1.2 Pin that the reference opens nothing: read stays with the counsellors and the reporter. Verify: PHPUnit `ConcernReportReadAccessTest::testNobodyButTheReporterAndTheCounsellorsReadsAReport` and `ConcernReportRegisterTest::testOnlyCounsellorsAndTheReporterReadAReport`.
- [x] 1.3 Pin where the pupil is filled in and where the field is left out. Verify: `node --test tests/unit-js/structureProfile.test.mjs`, the tests on Report a concern as a pupil-page action and as a start-page button.
