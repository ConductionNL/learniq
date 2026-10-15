# Tasks: quiet hours go to OpenRegister's delivery window

## 1. Panel

- [ ] 1.1 `src/views/LearniqNotificationSettings.vue` `fetchPreferences()`: read quiet hours from `GET /apps/openregister/api/notification-delivery-window` (a second request beside the preferences one); stop reading `data.quietHours`.
- [ ] 1.2 `saveQuietHours()`: `PUT` the same path with `{enabled, start, end, timezone}`; `enabled: false` when switched off. Handle 200, 422, 404 and other answers as in design D4.
- [ ] 1.3 Add the sentence that the window applies to every app (design D3), the board's explanation under "Quiet hours", and the one-line explanation per notification where the rule carries none.
- [ ] 1.4 Update the file's header comment and `@spec` tags to `openspec/specs/scholiq-notifications/spec.md#requirement-quiet-hours-are-read-and-saved-through-openregisters-delivery-window`.
- [ ] 1.5 English and Dutch strings. Verify: `npm run check:l10n`.

## 2. Tests and close out

- [ ] 2.1 Vitest for the panel: GET fills the fields; save sends the body with a time zone; 422 shows the message; 404 shows the fallback note.
- [ ] 2.2 Playwright `tests/e2e/notification-quiet-hours.spec.ts`: set 22:00 to 07:00, reload, read the values back. Tag with `@e2e`.
- [ ] 2.3 One live check on the dev instance: save, then `GET /apps/openregister/api/notification-delivery-window` as that user returns the window.
- [ ] 2.4 Set row `gov-choose-my-notifications` to `built`, `learniq: yes`; archive this change into `openspec/specs/scholiq-notifications`.
