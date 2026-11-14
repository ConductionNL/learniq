# Tasks: learner-list-and-page-follow-the-boards

- [ ] **T1**: `todayState` on the learner, from today's attendance and open excuse requests
  - Partly built: `Service/Attendance/TodayState` (the state from the day's marks and the covering report) and `TodayStateService::forCohort()` with the absent-today count. Left: putting it on the learner read model, and "until 11.30" (an excuse request holds no time of day yet).
- [ ] **T2**: list columns per school kind and saved views with counts, in the structure profile per example set
- [ ] **T3**: the page header and the "Wat nu?" rule (first applicable next step)
  - Partly built: `Service/Pupil/NextStep::first()`, the rule: a booked conference in the coming 14 days (with who chose the time and whether it is confirmed), else the next planned placement visit, else the oldest open attendance signal, each with at most three preparatory lines and one action, answered as data for the page to word. Left: the endpoint and the page header that show it.
- [ ] **T4**: the side column of people around the pupil
- [ ] **T5**: the guardian conversation on the page through portaliq's staff message endpoints, reply for participants only
- [ ] **T6**: Dutch strings; example data for Vera, Noor and Milan as on the boards
