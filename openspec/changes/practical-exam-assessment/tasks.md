# Tasks: practical-exam-assessment

- [x] **T1**: Ruben decides the signature route (widen `Signature.subjectKind`, recommended; or a second shape; or one signature record for everything)
  - Decided 2026-11-14 for this build: option 1, the recommended one. `Signature.subjectKind` gains `practical-exam-assessment`; it is additive, keeps one signature shape a report can read, and costs one register version.
- [x] **T2**: register: `ExamAssessorAssignment` (`examSittingId`, `externalAssessorId`, `learnerRef`, `role`, `lifecycle`, `tenant_id`)
  - `npm run check:register`; a payload test against the shipped fragment
- [x] **T3**: register: `PracticalExamAssessment` (`examSittingId`, `learnerRef`, `assessmentModelId`, `criteria`, `outcome`, `assessorId`, `assessorName`, `assuranceLevel`, `lifecycle`, `tenant_id`), mirroring what `WerkprocesAssessment` records about its assessor
- [x] **T4**: register: the signature route from T1, with `assuranceLevel` on every signature
  - Built: `Signature` 0.3.0 accepts `subjectKind: practical-exam-assessment` and `signerRole: assessor`; `assuranceLevel` stays required on every signature; register 0.50.0. `PracticalExamRegisterTest::testBothAssessorsSignTheAssessmentWithTheirAssuranceLevel`.
- [ ] **T5**: assessor portal: `eaExamsToday` (his assignments for today, joined to their sitting), `eaExams` (all of them), and the assessment form as an `endpoint-forward`, so the assertion names who assessed
- [ ] **T6**: the form saves a draft on every step, and a reconnect resumes it
- [ ] **T7**: the result reaches the exam board only when both assessors have signed
- [ ] **T8**: the sitting's documents (assessment model, regulations) are readable by an assigned assessor and by nobody else
- [ ] **T9**: e2e in `assessor-flows.spec.ts`: his day lists only his own candidates, he fills a form, a second assessor signs, and a sitting he is not assigned to resolves nothing
- [ ] **T10**: example set: the mbo profile seeds a sitting with two assessors and three candidates
