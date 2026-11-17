# Tasks: exam-committee-today-and-keuzedelen

- [x] **T1**: register: `exam-objection` with lifecycle and term in working days (setting); rationale guard; a changed result through the publish path
  - `ExamObjection` (slug `exam-objection`, lifecycle submitted, examiner-responded, decided, withdrawn); `ExamObjectionDeadlineStamp` writes `submittedAt`, `termWorkingDays` (app setting `exam_objection_term_working_days`, default 10) and `decideBy` (`WorkingDays`); `ExamObjectionDecisionGuard` on decide; `ExamObjectionDecisionHandler` writes a changed result through the grade entry's revise and republish, so its audit trail keeps the old value. Tests: `WorkingDaysTest`, `ExamObjectionDeadlineStampTest`, `ExamObjectionDecisionGuardTest`, `ExamObjectionDecisionHandlerTest`, `ExamObjectionRegisterTest`.
- [x] **T2**: register: `establishedAt`, `establishedBy` on sitting results; "establish all" action for the committee
  - `GradeEntry` carries `examSittingId`, `establishedAt`, `establishedBy` (empty `establishedAt` = provisional); `ExamSitting` gets `establish` (planned -> established), guarded by `ExamSittingEstablishGuard` (exam committee: compliance-officers, admin) and stamped by `StampTransitionActorAction`; `ExamSittingEstablishHandler` writes the sitting's moment and actor onto every not yet established result of the sitting at once. Tests: `ExamSittingEstablishGuardTest`, `ExamSittingEstablishHandlerTest`, `ExamSittingEstablishRegisterTest`.
- [ ] **T3**: the committee's Today: first-today rules, counts per sitting, coming sittings, the agenda list of open items
- [ ] **T4**: `examProgressLabel` and the next sitting on the student
- [ ] **T5**: register: `keuzedeel-offer`, `keuzedeel-choice`; the student's choice action in her portal; the page line
- [ ] **T6**: mbo example set: the MT3A objection of 25 September, 14 results of 3 sittings, Milan's progress and the period 3 window
