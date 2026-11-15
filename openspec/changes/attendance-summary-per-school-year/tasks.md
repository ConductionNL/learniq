# Tasks: an attendance summary per learner per school year

- [x] 1.1 `AttendanceSummaryCalculator` holds the counting rules (school year from 1 August, day of a record, absent days with and without permission, late count and minutes). Verify: PHPUnit `AttendanceSummaryCalculatorTest`, red before the class existed.
- [x] 1.2 `AttendanceSummary` schema with read rules for group teachers (`teacherIds`), coordinators, administration-managers and compliance-officers, no client writes, Dutch catalogue keys. Verify: PHPUnit `AttendanceSummaryRegisterTest`.
- [x] 1.3 `AttendanceSummaryService` recounts a learner's years, saves only changed rows with a stable uuid and the system as owner. Verify: PHPUnit `AttendanceSummaryServiceTest`, including the written row against the shipped schema.
- [x] 1.4 `AttendanceSummaryListener` defers a recount on every AttendanceRecord create, update and delete, built with the real OpenRegister event classes. Verify: PHPUnit `AttendanceSummaryListenerTest`, `AttendanceSummaryRecomputeJobTest`, `RegisteredListenersHandleRealEventsTest`.
- [x] 1.5 `BackfillAttendanceSummaries` repair step, registered after `InitializeSettings`. Verify: PHPUnit `BackfillAttendanceSummariesTest`.
- [x] 1.6 The po example set ships the summaries, counted the same way. Verify: `python3 scripts/example-sets/po.py --check`; PHPUnit `AttendanceSummaryRegisterTest::testThePrimarySchoolSummariesMatchTheCalculator`, `ExampleSetDescriptorContractTest`.
- [x] 1.7 Contract for the parent portal lane in `/home/rubenlinde/memcap-work/lq-attendance/CONTRACT.md`.
- [ ] 1.8 Live: the summary of the Groep 7 pupils marked in the roll-call shows the new late arrival and absence after the background job runs.
