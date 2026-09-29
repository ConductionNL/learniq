# Tasks: join an online lesson from the timetable

## 1. Register and write path

- [ ] 1.1 Add `onlineMeetingUrl` to `Session` with https validation. Verify: `npm run check:register`, PHPUnit for https, http and javascript: values.

## 2. UI

- [ ] 2.1 Add the Join action to `MyTimetable.vue` and the session detail, and the field to the session edit dialog. Verify: vitest for the action; Playwright flow set a link, join from the timetable.

## 3. Close out

- [ ] 3.1 Add strings to every shipped locale. Verify: `npm run test:l10n`.
- [ ] 3.2 Archive the change; list row `tt-online-lesson-link` for the coordinator. Verify: parity_verify --strict on learniq.

