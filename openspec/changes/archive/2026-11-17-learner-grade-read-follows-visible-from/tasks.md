# Tasks: learner-grade-read-follows-visible-from

- [x] **T1**: the learner read rule on `GradeEntry` in `lib/Settings/learniq_register.json`
  - PHPUnit `GradeEntryLearnerReadTest`, `DeclaredAudienceEnforcedTest`
- [x] **T2**: live: after the register import, a learner's grade with `visibleFrom` tomorrow is absent from `/api/objects/learniq/grade-entry` and /my-grades
  - Live pass 17 Nov (fresh Nextcloud 34.0.6, OpenRegister 29890847e), `tests/e2e/spec-coverage/grade-visible-from.spec.ts`: the learner lists the grade released yesterday and neither the one held until tomorrow nor the concept one; reading the held one by id returns no grade; an instructor reads it. 2 passed. learniq has no `/my-grades` route; the learner's grade pages read this same list query.
