# Tasks: read your timetable in your normal calendar app

## 1. Feed

- [ ] 1.1 Extract the caller session resolution of `TimetableController::mine` into a service method used by both. Verify: PHPUnit that `mine` output is unchanged.
- [ ] 1.2 Add the token preference (create, hash, reset) and the public feed route with rate limiting. Verify: PHPUnit for valid, wrong and reset token; hydra gates 5, 7 and 30.
- [ ] 1.3 Write the iCalendar output (events, cancelled status, substitute, room, timezone). Verify: PHPUnit that parses the output with an iCalendar parser and checks a cancelled and a substituted lesson.

## 2. UI

- [ ] 2.1 Add Subscribe in your calendar and Reset link to the timetable page. Verify: Playwright flow copy address, fetch it, reset, old address 404.

## 3. Close out

- [ ] 3.1 Add strings to every shipped locale. Verify: `npm run test:l10n`.
- [ ] 3.2 Set row `att-timetable-in-my-calendar` to built; list planninq row `sib-learniq-att-timetable-in-my-calendar` for the coordinator. Verify: parity_verify --strict.

