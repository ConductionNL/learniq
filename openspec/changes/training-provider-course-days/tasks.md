# Tasks: training-provider-course-days

- [x] **T1**: register: `cohort.minParticipants`, `goDecisionBy`, `dayStatus` (derived); `course.bringList[]` (plus `cohort.goDecision`, the go/no-go the derivation reads)
- [ ] **T2**: status derivation on every booking, enrolment, decision and date change; manual move log
  - Derivation built: `CourseDayStatus` (rules), `CourseDayStatusService::restamp()`, queued by `CourseDayStatusCascade` and run by `CourseDayRestampJob`. The manual move log is not built yet, so the task stays open.
- [ ] **T3**: go/no-go action and moving participants to another day of the course
- [ ] **T4**: the course days board per status (library board view) and the calendar tab
- [ ] **T5**: session parts and attendance per part with arrival time; the trainer's Today
- [ ] **T6**: the planner's Today: first-today rules, enrolments to confirm, occupancy, trainers and rooms
- [ ] **T7**: participants list columns with the certificate colour rule; participant page header with steps and "Wat nu?"
- [ ] **T8**: training example set: 22 October at 4 of 6, the 5 October practical day in parts, Tom's next day with the bring list
