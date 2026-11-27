# Tasks: place a student's elective choices in their timetable

## 1. Timetable

- [x] 1.1 Add enrolment based session resolution to the timetable source and merge without duplicates. Verify: PHPUnit for elective, withdrawn and duplicate cases. Done: `TimetableSource::sessionsForCourses()`, `PersonalTimetableService` (D3); `TimetableControllerTest::testAChosenElectiveAppears`, `testAWithdrawnEnrolmentDisappears`, `testNoDuplicatesWhenCohortAndEnrolmentBothReachALesson`, `testPlanninqIsNeverAskedByCourse`.
- [x] 1.9 Live pass D8 (DECISIONS row 53): with planninq as the source, `PlanninqTimetableSource::sessionsForCourses()` asks planninq by `courseId` from contract v2 (`for-ruben/planninq-timetable-course-query-and-lesson-link.md`); below v2 it asks nothing, as before. Verify: `PlanninqCourseQueryTest` against a fake of the v2 contract, and from the caller `PlanninqTimetableCallerTest::testTwoOverlappingPlanninqElectivesBothShowTheirSlot` (the live-pass `course-slots` request). Red before the code: `~/memcap-work/build-all/learniq/lanefix2/d8-red.log`.
- [ ] 1.10 Live check with planninq on contract v2 (needs the planninq change): two overlapping planninq electives warn in the picker.

## 2. Picker

- [x] 2.1a Show slots and the overlap warning in the picker (D4). Verify: node test `tests/unit-js/electiveSlots.test.mjs` for the overlap logic; `ElectiveSlotsControllerTest` for the slots route.
- [x] 2.1b Verify: Playwright flow choose two overlapping electives.
  - Written: `tests/e2e/elective-overlap-picker.spec.ts` (two electives on Tuesday 10:30 warn in the picker, a chosen elective of another group shows in My timetable, a lesson reached through the group and an enrolment shows once, a withdrawn enrolment leaves). Not run here; tick after a run on an instance.
  - Live 25 Nov 2026 on a sandbox Nextcloud 34.0.6 with PostgreSQL 16, OpenRegister main and learniq development: `online-lesson-link.spec.ts` 3/3 and `elective-overlap-picker.spec.ts` 3/3. The run first found and fixed: a learner listed in `Cohort.learnerIds` got an empty timetable (the cohort lookup ran RBAC-scoped; now read without RBAC and filtered on membership); the learner select of the picker and the conference booking was named "Learner" under its visible label, and its options read the user id; fixtures now create a course as a draft and publish it with a published lesson (OpenRegister refuses another initial state); the spec answers the meeting host itself and puts the clashing electives next week, inside the picker's window.

## 3. Close out

- [x] 3.1 Add strings to every shipped locale (en, nl). Verify: `npm run check:l10n`, `check:l10n-js`.
- [ ] 3.2 Archive the change; list row `tt-student-choice` for the coordinator. Verify: parity_verify --strict on learniq.

