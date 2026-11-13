# Tasks: employer-substitutes-a-colleague

- [x] **T1**: register: `cohort.substituteUntil` (optional; default the working day before at 12.00)
  > Cohort 0.3.1; `SubstitutionDeadline` reads it, else the working day before the first course day at 12.00 Amsterdam time.
- [x] **T2**: employer action `substituteParticipant` (own organisation only; withdraw and enrol in one step; exam registration line moves)
  > `POST /api/portal/employer/substitutions` → `PortalEmployerBookings::substituteParticipant()`. learniq has no exam registration line on a booking or enrolment to move; the substitute's enrolment copies the replaced one's course date, booking and status.
- [ ] **T3**: the notice to the replaced participant; the invitation of the new one
- [ ] **T4**: the row link "Collega in zijn plaats" through portaliq's row action with an input (`case-actions-row-inputs-and-conditions`)
- [ ] **T5**: e2e in `tests/e2e/portal-design/warmtepompacademie.spec.ts`
