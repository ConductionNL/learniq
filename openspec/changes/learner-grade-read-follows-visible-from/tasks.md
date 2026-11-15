# Tasks: learner-grade-read-follows-visible-from

- [x] **T1**: the learner read rule on `GradeEntry` in `lib/Settings/learniq_register.json`
  - PHPUnit `GradeEntryLearnerReadTest`, `DeclaredAudienceEnforcedTest`
- [ ] **T2**: live: after the register import, a learner's grade with `visibleFrom` tomorrow is absent from `/api/objects/learniq/grade-entry` and /my-grades
