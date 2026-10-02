# Tasks: place a student's elective choices in their timetable

## 1. Timetable

- [x] 1.1 Add enrolment based session resolution to the timetable source and merge without duplicates. Verify: PHPUnit for elective, withdrawn and duplicate cases. Done: `TimetableSource::sessionsForCourses()`, `PersonalTimetableService` (D3); `TimetableControllerTest::testAChosenElectiveAppears`, `testAWithdrawnEnrolmentDisappears`, `testNoDuplicatesWhenCohortAndEnrolmentBothReachALesson`, `testPlanninqIsNeverAskedByCourse`.
- [x] 1.9 Live pass D8 (DECISIONS row 53): with planninq as the source, `PlanninqTimetableSource::sessionsForCourses()` asks planninq by `courseId` from contract v2 (`for-ruben/planninq-timetable-course-query-and-lesson-link.md`); below v2 it asks nothing, as before. Verify: `PlanninqCourseQueryTest` against a fake of the v2 contract. Red before the code: `~/memcap-work/build-all/learniq/lanefix/d8-red.log`.
- [ ] 1.10 Live check with planninq on contract v2 (needs the planninq change): two overlapping planninq electives warn in the picker.

## 2. Picker

- [x] 2.1a Show slots and the overlap warning in the picker (D4). Verify: node test `tests/unit-js/electiveSlots.test.mjs` for the overlap logic; `ElectiveSlotsControllerTest` for the slots route.
- [ ] 2.1b Verify: Playwright flow choose two overlapping electives.

## 3. Close out

- [x] 3.1 Add strings to every shipped locale (en, nl). Verify: `npm run check:l10n`, `check:l10n-js`.
- [ ] 3.2 Archive the change; list row `tt-student-choice` for the coordinator. Verify: parity_verify --strict on learniq.

