# Tasks: practical-exam-assessment

- [ ] **T1**: Ruben decides the signature route (widen `Signature.subjectKind`, recommended; or a second shape; or one signature record for everything)
- [ ] **T2**: register: `ExamAssessorAssignment` (`examSittingId`, `externalAssessorId`, `learnerRef`, `role`, `lifecycle`, `tenant_id`)
  - `npm run check:register`; a payload test against the shipped fragment
- [ ] **T3**: register: `PracticalExamAssessment` (`examSittingId`, `learnerRef`, `assessmentModelId`, `criteria`, `outcome`, `assessorId`, `assessorName`, `assuranceLevel`, `lifecycle`, `tenant_id`), mirroring what `WerkprocesAssessment` records about its assessor
- [ ] **T4**: register: the signature route from T1, with `assuranceLevel` on every signature
- [ ] **T5**: assessor portal: `eaExamsToday` (his assignments for today, joined to their sitting), `eaExams` (all of them), and the assessment form as an `endpoint-forward`, so the assertion names who assessed
- [ ] **T6**: the form saves a draft on every step, and a reconnect resumes it
- [ ] **T7**: the result reaches the exam board only when both assessors have signed
- [ ] **T8**: the sitting's documents (assessment model, regulations) are readable by an assigned assessor and by nobody else
- [ ] **T9**: e2e in `assessor-flows.spec.ts`: his day lists only his own candidates, he fills a form, a second assessor signs, and a sitting he is not assigned to resolves nothing
- [ ] **T10**: example set: the mbo profile seeds a sitting with two assessors and three candidates
