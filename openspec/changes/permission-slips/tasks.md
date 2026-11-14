# Tasks: permission-slips

- [x] **T1**: register: `PermissionRequest` (`title`, `explanation`, `happensOn`, `cohortId`, `learnerRefs`, `respondBy`, `treatSilenceAsRefusal`, `lifecycle`, `tenant_id`)
  - `npm run check:register`; a payload test against the shipped fragment
- [x] **T2**: register: `PermissionResponse` (`permissionRequestId`, `learnerRef`, `answer`, `answeredByRef`, `answeredAt`, `assuranceLevel`, `tenant_id`), one per learner per request
- [x] **T3**: the guardian's portal: collection `parentPermissionRequests` (through her existing child join) and action `answerPermissionRequest`, with the answer stamped to her own child
- [x] **T4**: the request appears as a task on her overview with its deadline, like the conference booking already does
- [x] **T5**: a school floor for the assurance level of an answer, defaulting to `basic`, refused below it with the required level named
- [ ] **T6**: the teacher's screen: answered yes, answered no, not answered, per request
- [ ] **T7**: e2e in `po-parent-flows.spec.ts`: the teacher opens a request, the guardian answers for her own child and not for another, the teacher sees the outcome
- [ ] **T8**: example set: the po profile seeds one open request for Vera's group and one closed one

## Build notes (2026-11-14)

- T3: `parentPermissionRequests` (open requests whose `learnerRefs` name one of
  her children, through the shared child join) and `answerPermissionRequest`,
  a row action forwarded to `POST /api/portal/permission-answers`
  (`PortalPermissionController` → `PortalPermissionAnswer`). The receiver
  checks the child is hers and covered by the request, refuses a closed or
  overdue request (409), stamps `answeredByRef`, `answeredAt` and
  `assuranceLevel`, and replaces an earlier answer for the same child. A
  request must name its learners in `learnerRefs`; a request that names only a
  `cohortId` is not yet expanded to its learners.
- T4: a second `tasks` block on the overview over the same collection, due on
  `respondBy`. The task stays until the request closes, also after she
  answered, because the collection has no per-child answered state yet.
- T5: `permission_answer_min_assurance` (default `basic`); below it the answer
  is refused with the required level named.
- Left: T6 (the teacher's screen), T7 (Playwright), T8 (example set).
