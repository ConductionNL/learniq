# Tasks: portal-refusals-name-their-fields

- [x] **T1**: `approveHourWeek` declares `schema: bpv-hour-week`
  - PHPUnit `PortalContributionProviderTest`
- [x] **T2**: `PortalAnswers`; the five portal endpoints name the fields of a refused form in `errors` and `invalid`
  - PHPUnit `PortalAnswersTest`, `PortalHourWeekApprovalTest`, `PortalWerkprocesAssessmentTest`, `PortalEmployerBookingsTest`
- [ ] **T3**: live check on the proof instance: Petra approves hours with a number input; Linda books 13 places and reads the refusal on the number of participants
