# Tasks: leave-requests-decided-by-the-school

- [ ] **T1**: register: `leave-request` (`learnerRef`, `requestedByRef`, `dateFrom`, `dateTo`, `kind`, `reason`, `attachmentRef`, `schoolDays`, `decideBy`, `decidedBy`, `decidedAt`, `decisionNote`, `lifecycle`, `teacherIds`, `tenant_id`); the lifecycle with the decider guard
  - `npm run check:register`; a payload test against the shipped fragment
- [ ] **T2**: school days in the range and the deadline (`decideBy`, setting `leave_decision_school_days`, default 5)
- [ ] **T3**: an allowed request writes absent-with-permission for its days, through the same path as an approved excuse
- [ ] **T4**: staff page "Verlof te beslissen" with allow, refuse (note required) and forward; Dutch strings
- [ ] **T5**: parent portal: `requestLeave` action and `parentLeaveRequests` collection; the guardian overview names an open decision
- [ ] **T6**: example sets: po two open requests (El Idrissi, Jansen), vo two new ones for the bovenbouw
- [ ] **T7**: e2e in `tests/e2e/portal-design/wilgenboom.spec.ts`: Fatima asks, the directeur allows, Fatima reads the decision
