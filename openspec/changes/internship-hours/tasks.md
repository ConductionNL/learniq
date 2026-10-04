# Tasks: internship-hours

- [ ] **T1**: register: new schema `BpvHourWeek` (`bpvPlacementId`, `learnerRef`, `isoWeek`, `hoursSubmitted`, `submittedBy`, `submittedAt`, `hoursApproved`, `approvedBy`, `approvedAt`, `note`, `lifecycle`, `tenant_id`), and `BpvPlacement.agreedHours`
  - `npm run check:register`; a payload test against the shipped fragment
- [ ] **T2**: the week is scoped twice over: `practicalTrainerId` resolved from its placement for the trainer, `learnerRef` for the pupil
  - PHPUnit on the resolver, from the caller
- [ ] **T3**: trainer portal: collection `poHourWeeks` (filter `lifecycle: submitted`) and action `approveHourWeek` through learniq's own endpoint, so the assertion names who approved
  - PHPUnit on the provider's normalised output
- [ ] **T4**: pupil portal: action `submitHourWeek`, scoped by her own claim
- [ ] **T5**: the trainer's overview shows hours done against `agreedHours`, and shows hours alone when no total is agreed
- [ ] **T6**: learniq's own screens: hours on the placement detail page, and a per-cohort list for the school coach
- [ ] **T7**: e2e: the trainer approves a week in `trainer-flows.spec.ts`, and the pupil submits one in `pupil-flows.spec.ts`
- [ ] **T8**: example set: the mbo profile seeds weeks for both students, one waiting and one approved
