# Tasks: read your timetable in your normal calendar app

## 1. Feed

- [x] 1.1 Extract the caller session resolution of `TimetableController::mine` into a service method used by both. Verify: PHPUnit that `mine` output is unchanged. Done: `PersonalTimetableService::forUser()`; `TimetableControllerTest` (21 tests) runs against the real service unchanged.
- [x] 1.2 Add the token preference (create, hash, reset) and the public feed route with rate limiting. Verify: PHPUnit for valid, wrong and reset token; hydra gates 5, 7 and 30. Done: `TimetableFeedTokenService`, `TimetableFeedController`; `TimetableFeedControllerTest` (valid, wrong, reset, revoke, disabled owner, hash-only storage, volatile user cleared).
- [x] 1.3 Write the iCalendar output (events, cancelled status, substitute, room, timezone). Verify: PHPUnit that parses the output with an iCalendar parser and checks a cancelled and a substituted lesson. Done: `TimetableIcsWriter` + `TimetableFeedEventBuilder`, parsed with sabre/vobject (require-dev) in `TimetableIcsWriterTest` and `TimetableFeedControllerTest`, both visibility policies.

## 2. UI

- [x] 2.1a Add Subscribe in your calendar and Reset link to the timetable page (`src/dialogs/TimetableCalendarFeedDialog.vue`, button on `MyTimetable.vue`).
- [x] 2.1b Live flow on the shared dev instance (2 Oct, learniq 397e4cc8, register 0.34.32, browser as lp-learner on /my-timetable): Make an address, Copy address puts it on the clipboard, the address fetched signed out answers 200 text/calendar, Reset link gives a new address, the old one answers 404 and the new one 200. Evidence: `~/memcap-work/build-all/livepass/learniq/attendance-timetable-calendar-feed/RESULT.md` and its screenshots. Not seen live: event content (the test learner has no sessions); the unit tests cover it.

## 3. Close out

- [x] 3.1 Add strings to every shipped locale (en, nl). Verify: `npm run check:l10n`, `check:l10n-js`.
- [x] 3.2 Set row `att-timetable-in-my-calendar` to built; list planninq row `sib-learniq-att-timetable-in-my-calendar` for the coordinator. Verify: parity_verify --strict.

