# Tasks: attendance-warns-before-the-threshold

- [ ] **T1**: register: `attendance-summary` gains `unauthorisedHoursWindow`, `thresholdHours`, `windowLabel`; `attendance-threshold` gains `warnAtHours`
  - `npm run check:register`
- [ ] **T2**: the recount fills the window count on every attendance change and on the daily sweep
- [ ] **T3**: a staff list "Bijna 16 uur" (filter on the summary), readable by group teachers, mentor and head of school
- [ ] **T4**: example sets: mbo Sem Visser at 14, po Finn at 6 and Ryan at 12
- [ ] **T5**: unit tests for the window, the warning level and the drop below it
