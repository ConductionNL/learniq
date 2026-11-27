# Tasks: join an online lesson from the timetable

## 1. Register and write path

- [x] 1.1 Add `onlineMeetingUrl` to `Session` with https validation (register 0.34.28). Verify: `SessionOnlineMeetingUrlRegisterTest` (the real fragment through Opis: https stored; javascript:, http, data:, relative refused), `TimetableControllerTest::testAnOnlineLessonCarriesItsHttpsLinkOnly`, `npm run check:specs`.
- [x] 1.9 Live pass D8 (DECISIONS row 53): a planninq lesson carries `onlineMeetingUrl` (contract v2), and `PlanninqTimetableSource` passes it on. Verify: `PlanninqCourseQueryTest::testTheLessonLinkIsCarried`, and from the caller `PlanninqTimetableCallerTest::testAPlanninqLessonLinkReachesTheTimetable` (https reaches My timetable, `javascript:` does not).
- [ ] 1.10 Live check with planninq on contract v2 (needs the planninq change): Join on a planninq lesson opens the meeting.

## 2. UI

- [x] 2.1a Add the Join action to `MyTimetable.vue`; the session detail and edit form show the field from the schema (design D3); the feed event carries it as `URL`. Verify: node test `tests/unit-js/onlineLesson.test.mjs`, `TimetableFeedControllerTest`.
- [x] 2.1b Verify: Playwright flow set a link, join from the timetable.
  - Written: `tests/e2e/online-lesson-link.spec.ts` (Join opens the meeting in a new tab, a lesson without a link shows no Join, a `javascript:` link is refused on save). Not run here; tick after a run on an instance.
  - Live 25 Nov 2026 on a sandbox Nextcloud 34.0.6 with PostgreSQL 16, OpenRegister main and learniq development: `online-lesson-link.spec.ts` 3/3 and `elective-overlap-picker.spec.ts` 3/3. The run first found and fixed: a learner listed in `Cohort.learnerIds` got an empty timetable (the cohort lookup ran RBAC-scoped; now read without RBAC and filtered on membership); the learner select of the picker and the conference booking was named "Learner" under its visible label, and its options read the user id; fixtures now create a course as a draft and publish it with a published lesson (OpenRegister refuses another initial state); the spec answers the meeting host itself and puts the clashing electives next week, inside the picker's window.

## 3. Close out

- [x] 3.1 Add strings to every shipped locale (en, nl). Verify: `npm run check:l10n`, `check:l10n-js`, `check:schema-l10n`.
- [ ] 3.2 Archive the change; list row `tt-online-lesson-link` for the coordinator. Verify: parity_verify --strict on learniq.

