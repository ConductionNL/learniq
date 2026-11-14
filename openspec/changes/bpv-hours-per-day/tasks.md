# Tasks: bpv-hours-per-day

- [x] **T1**: register: `bpv-hour-week.days[]` (additive); `hoursSubmitted` as the sum of the days
  - `HourDayLines` works out each day's hours (end less start less break), keeps one line per date in date order and refuses a date outside the week; `HourWeekSubmissionStamp` stamps the lines and their sum on every write. `hoursSubmitted` is no longer `required`, since the server writes it on a week of days; a week with neither is refused.
- [x] **T2**: student action `writeHourDay` (create draft week, replace a day), draft and submit
  - `writeHourDay` forwards to `/api/portal/hour-weeks/day` (`PortalHourDayController`, `PortalHourDayWrite`): the placement must be hers, the day's ISO week picks the week, a new week is created as `draft`, the day replaces the line of the same date, the hours are summed again, and `send: send` submits (`submit` transition from `draft` and `rejected`). A week that waits for the trainer or is approved is refused (409). The trainer's list still reads `submitted` weeks only, so a draft is not among them.
- [ ] **T3**: `approveHourWeek` returned: required day and question; the notice to the student with the link to the day (D-5)
  - Done: the endpoint takes `outcome: returned`, `returnedDate` and `question`; a week with days needs both (approving none of a week with days is a return too), the question lands on that day line, the week goes to `rejected` ("Sent back") with nothing approved; refusals are 422 with a sentence. The `approveHourWeek` action now declares `outcome`, `returnedDate` and `question`. The notice: a return stamps `returnSubject` ("Vraag over je uren van dinsdag 29 september", `HourReturnSubject`), and the student's inbox collection `studentReturnedHours` reads her `rejected` weeks with that line as subject and the question as text.
  - Left: the "Uren aanpassen" link inside the message. Portaliq's inbox row has subject, body, date and attachments only, no action link; it needs a portaliq change.
- [ ] **T4**: the overview line for today's missing hours; the phone form fields (start, end, break, description, work processes, photo)
  - Done: the phone form fields on `writeHourDay` (day, start, end, break as none / 30 / 60 minutes, what she did, her own work processes, keep or send).
  - Left: the photo (portaliq writes files only through its upload into a record's folder, and a day line is not a record), and the overview line "Je uren van vandaag zijn nog niet geschreven", which needs a conditional overview block portaliq does not offer yet.
- [x] **T5**: student action `estimateWerkproces` on her own `werkproces-progress`
  - Already on development as `fillInSelfAssessment` (`StudentSelfAssessment`, "Fill in now"): an `update` of `selfAssessment` scoped by `learnerRef`, so another student's row is refused by the portal scope.
- [x] **T6**: mbo example set: week 40 returned on Tuesday 29 September with Petra's question; week 41 draft
  - Week 40 is one record with Monday to Wednesday (24 hours), returned about the Tuesday with the question on that line and the message line; week 41 is a draft with Monday 5 October.
- [ ] **T7**: e2e in `tests/e2e/portal-design/esdoornveen.spec.ts`: write Monday, keep, submit; Petra returns; Milan changes the day
