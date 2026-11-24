# Tasks: quiet hours go to OpenRegister's delivery window

## 1. Panel

- [x] 1.1 `src/views/LearniqNotificationSettings.vue` `fetchPreferences()`: read quiet hours from `GET /apps/openregister/api/notification-delivery-window` (a second request beside the preferences one); stop reading `data.quietHours`.
  - Moved: `LearniqNotificationSettings.vue` was removed (#1910); the dialog's notification tab is nextcloud-vue's `CnNotificationPreferences`. Built there as the generic `CnQuietHours` block (nextcloud-vue #2307: GET/PUT the delivery window with the browser time zone, `enabled: false` when off, 200/422/404/other per D4, the every-app sentence of D3, en/nl strings). Learniq turns it on with `:quietHours="true"` on CnAppRoot (`src/App.vue`, `tests/unit-js/quietHoursInSettings.test.mjs`). Released in nextcloud-vue 2.211.0; learniq depends on ^2.211.0. 1.3: the per-notification explanation stays the rule's own label (nothing in the library carries a second line per rule); 1.4: the library component carries the spec tag, learniq's `App.vue` and `quietHoursInSettings.test.mjs` carry this change's.
- [x] 1.2 `saveQuietHours()`: `PUT` the same path with `{enabled, start, end, timezone}`; `enabled: false` when switched off. Handle 200, 422, 404 and other answers as in design D4.
- [x] 1.3 Add the sentence that the window applies to every app (design D3), the board's explanation under "Quiet hours", and the one-line explanation per notification where the rule carries none.
- [x] 1.4 Update the file's header comment and `@spec` tags to `openspec/specs/scholiq-notifications/spec.md#requirement-quiet-hours-are-read-and-saved-through-openregisters-delivery-window`.
- [x] 1.5 English and Dutch strings. Verify: `npm run check:l10n`.

## 2. Tests and close out

- [x] 2.1 Vitest for the panel: GET fills the fields; save sends the body with a time zone; 422 shows the message; 404 shows the fallback note.
  - Written with the block in nextcloud-vue: `tests/components/CnQuietHours.spec.js` (#2307) covers all four cases and switching off. Released with 1.1.
- [x] 2.2 Playwright `tests/e2e/notification-quiet-hours.spec.ts`: set 22:00 to 07:00, reload, read the values back. Tag with `@e2e`.
  - Written, runs in nightly: reads the block by `cn-quiet-hours`, fields Begin and End; skips on a nextcloud-vue without the block.
- [ ] 2.3 One live check on the dev instance: save, then `GET /apps/openregister/api/notification-delivery-window` as that user returns the window.
- [ ] 2.4 Set row `gov-choose-my-notifications` to `built`, `learniq: yes`; archive this change into `openspec/specs/scholiq-notifications`.
