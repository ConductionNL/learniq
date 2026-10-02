# Tasks: join an online lesson from the timetable

## 1. Register and write path

- [x] 1.1 Add `onlineMeetingUrl` to `Session` with https validation (register 0.34.28). Verify: `SessionOnlineMeetingUrlRegisterTest` (the real fragment through Opis: https stored; javascript:, http, data:, relative refused), `TimetableControllerTest::testAnOnlineLessonCarriesItsHttpsLinkOnly`, `npm run check:specs`.

- [x] 1.9 Live pass D8 (DECISIONS row 53): a planninq lesson carries `onlineMeetingUrl` (contract v2), and `PlanninqTimetableSource` passes it on. Verify: `PlanninqCourseQueryTest::testTheLessonLinkIsCarried`.
- [ ] 1.10 Live check with planninq on contract v2 (needs the planninq change): Join on a planninq lesson opens the meeting.
## 2. UI

- [x] 2.1a Add the Join action to `MyTimetable.vue`; the session detail and edit form show the field from the schema (design D3); the feed event carries it as `URL`. Verify: node test `tests/unit-js/onlineLesson.test.mjs`, `TimetableFeedControllerTest`.
- [ ] 2.1b Verify: Playwright flow set a link, join from the timetable.

## 3. Close out

- [x] 3.1 Add strings to every shipped locale (en, nl). Verify: `npm run check:l10n`, `check:l10n-js`, `check:schema-l10n`.
- [ ] 3.2 Archive the change; list row `tt-online-lesson-link` for the coordinator. Verify: parity_verify --strict on learniq.

