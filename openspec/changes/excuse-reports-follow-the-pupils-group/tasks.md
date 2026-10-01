# Tasks: an absence report reaches the pupil's group teachers and school-wide staff

- [x] 1.1 `PupilGroupTeachers` resolves the teachers of a pupil's current groups. Verify: PHPUnit `ExcuseRequestOwnerStampTest::testAPortalReportListsTheTeachersOfThePupilsGroup`.
- [x] 1.2 `ExcuseRequestOwnerStamp` stamps `teacherIds` on every write, ignoring a client value, and fails closed. Verify: PHPUnit `ExcuseRequestOwnerStampTest` (testAClientCannotAddItselfToTheAudience, testAnUpdateReDerivesTheTeachers, testAFailedGroupLookupStampsNobodyOnCreate, testAnUpdateKeepsTheStoredTeachersWhenTheLookupFails), red before, green after.
- [x] 1.3 Declare `ExcuseRequest.teacherIds` with catalogue keys (en, nl) and scope the read and update rules. Verify: PHPUnit `ExcuseRequestRegisterTest::testATeacherReadsOnlyTheReportsOfTheirOwnGroups`, `testTheTeacherFieldIsDeclaredAndTranslated`; `npm run check:schema-l10n`.
- [x] 1.4 What the stamp writes passes the shipped schema. Verify: PHPUnit `ExcuseRequestOwnerStampTest::testTheStampedReportPassesTheRealSchema`.
- [x] 1.5 `BackfillExcuseRequestTeachers` back-fills existing reports, registered after `InitializeSettings`. Verify: PHPUnit `BackfillExcuseRequestTeachersTest`.
- [ ] 1.6 Live: as `po-leerkracht-09` (Groep 7), `po-ib-01` (coordinator) and `po-directeur-01` (director), open `/attendance/excuses` and check who sees which reports.
