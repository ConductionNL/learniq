# Tasks: internship-hours

> Archive pass 2026-10-07: code done; open: T7 (live check: the trainer and pupil e2e steps have not passed live yet).

- [x] **T1**: register: new schema `BpvHourWeek` (`bpvPlacementId`, `learnerRef`, `isoWeek`, `hoursSubmitted`, `submittedBy`, `submittedAt`, `hoursApproved`, `approvedBy`, `approvedAt`, `note`, `lifecycle`, `tenant_id`), and `BpvPlacement.agreedHours`
  - `npm run check:register`; a payload test against the shipped fragment
  - `tenant_id` is NOT in the schema's `required` list: OpenRegister validates `required` before any listener runs, so a field cannot be both required and server-stamped. `HourWeekSubmissionStamp` fills it and refuses a week without a school.
- [x] **T2**: the week is scoped twice over: `practicalTrainerId` resolved from its placement for the trainer, `learnerRef` for the pupil
  - PHPUnit on the resolver, from the caller
  - the reverse join's `targetField` is the placement's own `id`, not `bpvPlacementId`: portaliq reads it on the JOIN row, so the first spelling resolved no weeks at all
- [x] **T3**: trainer portal: collection `poHourWeeks` (filter `lifecycle: submitted`) and action `approveHourWeek` through learniq's own endpoint, so the assertion names who approved
  - PHPUnit on the provider's normalised output
  - she picks the week from an `optionsProviders` list over her own collection, so no uuid is ever typed
- [x] **T4**: pupil portal: action `submitHourWeek`, scoped by her own claim
  - `crossRefs` holds the placement to her own, or her hours would land on another student's placement and the rollup would add them to that placement's total
  - `studentBpvPlacements` is declared so the placement picker has something to read; its page stays out of her short menu
- [x] **T5**: the trainer's overview shows hours done against `agreedHours`, and shows hours alone when no total is agreed
  - the card is `display: cards` with `progress: {valueField, totalField, label}`, and both fields are projected: portaliq drops a progress whose fields the collection does not project
- [x] **T6**: learniq's own screens: hours on the placement detail page, and a list for the school coach
  - `src/manifest.d/work-placement.json`: an "Hours per week" list on `BpvPlacementDetail`, and the index page `BpvHourWeeks` (`/bpv/hours`, menu "BPV hours") with the pupil read by name, newest week first. A list per cohort is the same page filtered; a week carries no cohort, so a cohort filter would need one (not added).
- [x] **T9**: the placement keeps waiting and returned hours beside the approved ones; the student's hours page opens with the segmented bar; the mbo set seeds the totals
  - PHPUnit `HourWeekListenersTest`, `GuardianSitePagesTest::testTheStudentHoursPageOpensWithTheBar`, `VocationalCollegeExampleSetTest`
- [x] **T7**: e2e: the trainer approves a week in `trainer-flows.spec.ts`, and the pupil submits one in `pupil-flows.spec.ts`
  - both suites clean up every row they create; both steps skip on an instance whose learniq predates this change, and said so when run against :8090
  - NOT yet seen pass live: :8090 still runs learniq 0.3.8, which has no `BpvHourWeek`
- [x] **T8**: example set: the mbo profile seeds weeks for both students, one waiting and one approved
  - the first two placements of every BPV unit carry six weeks each: four approved as entered, one corrected with the trainer's reason, one still waiting. 60 rows, and every placement states its `agreedHours`.
