# Tasks: bpv-hours-per-day

- [x] **T1**: register: `bpv-hour-week.days[]` (additive); `hoursSubmitted` as the sum of the days
  - `HourDayLines` works out each day's hours (end less start less break), keeps one line per date in date order and refuses a date outside the week; `HourWeekSubmissionStamp` stamps the lines and their sum on every write. `hoursSubmitted` is no longer `required`, since the server writes it on a week of days; a week with neither is refused.
- [ ] **T2**: student action `writeHourDay` (create draft week, replace a day), draft and submit
- [ ] **T3**: `approveHourWeek` returned: required day and question; the notice to the student with the link to the day (D-5)
  - Done: the endpoint takes `outcome: returned`, `returnedDate` and `question`; a week with days needs both (approving none of a week with days is a return too), the question lands on that day line, the week goes to `rejected` ("Sent back") with nothing approved; refusals are 422 with a sentence. Left: the trainer form fields on the `approveHourWeek` action (portaliq forwards declared fields only) and the student's notice, for which learniq has no portal inbox writer yet.
- [ ] **T4**: the overview line for today's missing hours; the phone form fields (start, end, break, description, work processes, photo)
- [x] **T5**: student action `estimateWerkproces` on her own `werkproces-progress`
  - Already on development as `fillInSelfAssessment` (`StudentSelfAssessment`, "Fill in now"): an `update` of `selfAssessment` scoped by `learnerRef`, so another student's row is refused by the portal scope.
- [ ] **T6**: mbo example set: week 40 returned on Tuesday 29 September with Petra's question; week 41 draft
- [ ] **T7**: e2e in `tests/e2e/portal-design/esdoornveen.spec.ts`: write Monday, keep, submit; Petra returns; Milan changes the day
