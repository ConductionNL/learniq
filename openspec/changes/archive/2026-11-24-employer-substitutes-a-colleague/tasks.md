# Tasks: employer-substitutes-a-colleague

- [x] **T1**: register: `cohort.substituteUntil` (optional; default the working day before at 12.00)
  > Cohort 0.3.1; `SubstitutionDeadline` reads it, else the working day before the first course day at 12.00 Amsterdam time.
- [x] **T2**: employer action `substituteParticipant` (own organisation only; withdraw and enrol in one step; exam registration line moves)
  > `POST /api/portal/employer/substitutions` → `PortalEmployerBookings::substituteParticipant()`. learniq has no exam registration line on a booking or enrolment to move; the substitute's enrolment copies the replaced one's course date, booking and status.
- [x] **T3**: the notice to the replaced participant; the invitation of the new one
  > `ParticipantSitePages::substitutedRule()`: portal rule `enrolment.substituted` on `participantCourseDays`, addressed by the `learnerRef` claim, worded only for the reason `vervangen door collega` ("Een collega gaat in uw plaats"). The substitute's enrolment is created like any participant's enrolment (`addParticipant` sends no invitation of its own), so it needs no rule of its own.
- [x] **T4**: the row link "Collega in zijn plaats" through portaliq's row action with an input (`case-actions-row-inputs-and-conditions`)
  > `EmployerSitePages::substituteAction()`: an `endpoint-forward` row action on `employerParticipants` (`rowField: enrolmentId`, shown on a `pending` or `active` participant), the colleague picked from her own employees. The receiver also takes the row's `enrolmentId` and reads the booking and the replaced participant from her own company's enrolment. The booking's participants block is a table now, since portaliq draws row actions in its table only. PHPUnit `EmployerSubstitutionTest::testTheRowActionNamesTheParticipantByHerEnrolment`, `EmployerSitePagesTest::testAParticipantRowOffersAColleagueInTheirPlace`.
- [x] **T5**: e2e in `tests/e2e/portal-design/warmtepompacademie.spec.ts`
  - Written, runs in nightly (tests/e2e/portal-design/warmtepompacademie.spec.ts); ticked under the unblock rule, not verified live here.
  > Written: "Linda sends a colleague instead" on a booking the run makes and removes. Tick it after a live run.
