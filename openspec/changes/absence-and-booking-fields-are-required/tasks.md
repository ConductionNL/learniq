# Tasks: absence-and-booking-fields-are-required

- [x] **T1**: register 0.34.36: `ExcuseRequest` 0.4.1 and `ConferenceSignup` 0.2.1 require `learnerRef`, not nullable
  - PHPUnit `RequiredLearnerRefRegisterTest`, `ExcuseRequestRegisterTest`; `npm run check:register`
- [x] **T2**: the staff booking view sends `learnerRef` (`conferenceSignupBody`)
  - `node --test tests/unit-js/conferenceSignupBody.test.mjs`
- [x] **T3**: `BackfillRequiredLearnerRefs`, before `BackfillExcuseRequestTeachers`; app version stepped
  - PHPUnit `BackfillRequiredLearnerRefsTest`
- [x] **T4**: the live e2e follows portaliq#1130's date groups and the optional "Tijd"
  - `tests/e2e/po-parent-flows.spec.ts` (run live after portaliq#1130 lands)

## Waiting on Ruben

- `slotId` and `conferenceRoundId` on ConferenceSignup (see the proposal).
