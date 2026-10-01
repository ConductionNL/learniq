# Tasks: read your timetable in your normal calendar app

## 1. Feed

- [x] 1.1 Extract the caller session resolution of `TimetableController::mine` into a service method used by both. Verify: PHPUnit that `mine` output is unchanged. Done: `PersonalTimetableService::forUser()`; `TimetableControllerTest` (21 tests) runs against the real service unchanged.
- [x] 1.2 Add the token preference (create, hash, reset) and the public feed route with rate limiting. Verify: PHPUnit for valid, wrong and reset token; hydra gates 5, 7 and 30. Done: `TimetableFeedTokenService`, `TimetableFeedController`; `TimetableFeedControllerTest` (valid, wrong, reset, revoke, disabled owner, hash-only storage, volatile user cleared).
- [x] 1.3 Write the iCalendar output (events, cancelled status, substitute, room, timezone). Verify: PHPUnit that parses the output with an iCalendar parser and checks a cancelled and a substituted lesson. Done: `TimetableIcsWriter` + `TimetableFeedEventBuilder`, parsed with sabre/vobject (require-dev) in `TimetableIcsWriterTest` and `TimetableFeedControllerTest`, both visibility policies.

## 2. UI

- [x] 2.1a Add Subscribe in your calendar and Reset link to the timetable page (`src/dialogs/TimetableCalendarFeedDialog.vue`, button on `MyTimetable.vue`).
- [ ] 2.1b Verify: Playwright flow copy address, fetch it, reset, old address 404.

## 3. Close out

- [x] 3.1 Add strings to every shipped locale (en, nl). Verify: `npm run check:l10n`, `check:l10n-js`.
- [ ] 3.2 Set row `att-timetable-in-my-calendar` to built; list planninq row `sib-learniq-att-timetable-in-my-calendar` for the coordinator. Verify: parity_verify --strict.

