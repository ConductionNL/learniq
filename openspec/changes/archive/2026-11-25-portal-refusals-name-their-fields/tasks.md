# Tasks: portal-refusals-name-their-fields

- [x] **T1**: `approveHourWeek` declares `schema: bpv-hour-week`
  - PHPUnit `PortalContributionProviderTest`
- [x] **T2**: `PortalAnswers`; the five portal endpoints name the fields of a refused form in `errors` and `invalid`
  - PHPUnit `PortalAnswersTest`, `PortalHourWeekApprovalTest`, `PortalWerkprocesAssessmentTest`, `PortalEmployerBookingsTest`
- [x] **T3**: live check on the proof instance: Petra approves hours with a number input; Linda books 13 places and reads the refusal on the number of participants
  - Live 25 Nov 2026 (NC 34.0.6 + PostgreSQL 16 + OpenRegister main + portaliq development, mbo and training sets), twice: `esdoornveen.spec.ts` "Petra approves the week in the form, the hours as a number" (the form's "Uren die u goedkeurt" is `type=number`, 5.5 is stored as the number 5.5, the week `approved`); `warmtepompacademie.spec.ts` "Linda asks for 13 places" (the forward answers 422 `{error: 'incomplete', invalid: {participantCount: 'range'}}`). The form itself never sends 13: portaliq's count stepper keeps a typed count within 1 to 12 (site-forms). It showed a second typed 13 while it sent 12; fixed in ConductionNL/portaliq#2830.
