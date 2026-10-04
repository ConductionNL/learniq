# Tasks: permission-slips

- [ ] **T1**: register: `PermissionRequest` (`title`, `explanation`, `happensOn`, `cohortId`, `learnerRefs`, `respondBy`, `treatSilenceAsRefusal`, `lifecycle`, `tenant_id`)
  - `npm run check:register`; a payload test against the shipped fragment
- [ ] **T2**: register: `PermissionResponse` (`permissionRequestId`, `learnerRef`, `answer`, `answeredByRef`, `answeredAt`, `assuranceLevel`, `tenant_id`), one per learner per request
- [ ] **T3**: the guardian's portal: collection `parentPermissionRequests` (through her existing child join) and action `answerPermissionRequest`, with the answer stamped to her own child
- [ ] **T4**: the request appears as a task on her overview with its deadline, like the conference booking already does
- [ ] **T5**: a school floor for the assurance level of an answer, defaulting to `basic`, refused below it with the required level named
- [ ] **T6**: the teacher's screen: answered yes, answered no, not answered, per request
- [ ] **T7**: e2e in `po-parent-flows.spec.ts`: the teacher opens a request, the guardian answers for her own child and not for another, the teacher sees the outcome
- [ ] **T8**: example set: the po profile seeds one open request for Vera's group and one closed one
