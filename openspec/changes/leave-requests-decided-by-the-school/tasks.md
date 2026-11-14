# Tasks: leave-requests-decided-by-the-school

- [x] **T1**: register: `leave-request` (`learnerRef`, `requestedByRef`, `dateFrom`, `dateTo`, `kind`, `reason`, `attachmentRef`, `schoolDays`, `decideBy`, `decidedBy`, `decidedAt`, `decisionNote`, `lifecycle`, `teacherIds`, `tenant_id`); the lifecycle with the decider guard
  - `npm run check:register`; a payload test against the shipped fragment
- [x] **T2**: school days in the range and the deadline (`decideBy`, setting `leave_decision_school_days`, default 5)
- [x] **T3**: an allowed request writes absent-with-permission for its days, through the same path as an approved excuse
- [x] **T4**: staff page "Verlof te beslissen" with allow, refuse (note required) and forward; Dutch strings
  > `LeaveRequests` (open requests, the deadline first) and `LeaveRequestDetail` in `src/manifest.d/people.json`, menu entry `LeaveRequestsMenu` for the administration-manager and admin (school-only; in the simple profile a header link on Attendance). The transitions carry their labels and ask `decisionNote`, required on `refuse`. The schema strings are now English source keys with Dutch values in `l10n/nl.json`.
- [x] **T5**: parent portal: `requestLeave` action and `parentLeaveRequests` collection; the guardian overview names an open decision
  > `ParentLeaveRequests`: `requestLeave` (scope `requestedByRef` from the guardian claim, `learnerRef` a required cross-reference to her own children), `parentLeaveRequests` (days, state in words, decision note) and `parentOpenLeaveRequests` (server filter `submitted`) on the overview's "Waiting for the school" block, and the page `parentLeave`. `LeaveRequestPortalStamp` checks the child again on the server ("Verlof vragen kan alleen voor uw eigen kind.") and stamps the pupil's group teachers and tenant.
- [x] **T6**: example sets: po two open requests (El Idrissi, Jansen), vo two new ones for the bovenbouw
  > `add_leave_requests` in `scripts/example-sets/po.py` (groep 7 wedding, groep 4 family occasion) and `vo.py` (4H1, 5V1), appended last so no earlier uuid moves; the sets' own fictional families stand in for the board's names.
- [ ] **T7**: e2e in `tests/e2e/portal-design/wilgenboom.spec.ts`: Fatima asks, the directeur allows, Fatima reads the decision
