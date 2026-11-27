# Tasks: permission-slips

- [x] **T1**: register: `PermissionRequest` (`title`, `explanation`, `happensOn`, `cohortId`, `learnerRefs`, `respondBy`, `treatSilenceAsRefusal`, `lifecycle`, `tenant_id`)
  - `npm run check:register`; a payload test against the shipped fragment
- [x] **T2**: register: `PermissionResponse` (`permissionRequestId`, `learnerRef`, `answer`, `answeredByRef`, `answeredAt`, `assuranceLevel`, `tenant_id`), one per learner per request
- [x] **T3**: the guardian's portal: collection `parentPermissionRequests` (through her existing child join) and action `answerPermissionRequest`, with the answer stamped to her own child
- [x] **T4**: the request appears as a task on her overview with its deadline, like the conference booking already does
- [x] **T5**: a school floor for the assurance level of an answer, defaulting to `basic`, refused below it with the required level named
- [x] **T6**: the teacher's screen: answered yes, answered no, not answered, per request
- [x] **T7**: e2e in `po-parent-flows.spec.ts`: the teacher opens a request, the guardian answers for her own child and not for another, the teacher sees the outcome
  - Written, runs in nightly (tests/e2e/po-parent-flows.spec.ts); ticked under the unblock rule, not verified live here.
- [x] **T8**: example set: the po profile seeds one open request for Vera's group and one closed one

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
- T6: `Permission slips` (`/permission-slips`, staff roles, school segment)
  lists the requests; `PermissionRequestDetail` carries the close action and
  the `PermissionRequestOutcome` body section: the learners the request names,
  split into not answered, answered yes and answered no, by name, with whether
  silence counts as no. The split is `utils/permissionOutcome.js`, pinned by
  `tests/unit-js/permissionOutcome.test.mjs`; the latest answer per child
  counts. Like T3, only `learnerRefs` are counted, not a bare `cohortId`.
- T8: `po.py` seeds groep 7's trip to the Openluchtmuseum (open, answer by
  16 October, nobody answered yet, so the e2e can answer for Vera) and the
  September sports day (closed, every pupil but the last four answered by a
  guardian, every fifth of them no); pinned in `PrimarySchoolExampleSetTest`.
- Left: T7 (Playwright e2e, needs a live instance). Round 2: flow `i.` in
  `po-parent-flows.spec.ts` is written (the teacher asks for Vera and a
  classmate, the guardian's answer for another child is refused, hers is
  stored with her ref and assurance, the teacher's page reads 1 of 2 answered
  and Vera under Answered yes); not run here, no live instance.
