---
kind: code
---

# Quiet hours go to OpenRegister's delivery window

## Why

Board `LqMeldingen` on canvas part 2 (`QAAxpcsFKBCvDUGbQbwtCa`) draws learniq's notification settings: one switch per notification and a quiet-hours block with a start and an end. Decision 99 (8 Oct) gives it a row.

The switches work. Quiet hours do not, and the reason is in learniq, not in OpenRegister. `src/views/LearniqNotificationSettings.vue:333-352` sends `{quietHours}` to `/apps/openregister/api/notification-preferences`, which only takes a `(schema, notification)` override, so every save fails and the page tells the user their Nextcloud does not enforce quiet hours yet. OpenRegister shipped the delivery window in its `notification-delivery-windows` change (archived 13 Jul): `GET` and `PUT /api/notification-delivery-window` (openregister `appinfo/routes.php:1437-1438`) store `{enabled, start, end, timezone, days}` per user, and its dispatcher defers delivery inside the window. Learniq never moved to that endpoint.

### Matrix rows (`openspec/parity/capabilities.json`)

| row | capability | Today |
|---|---|---|
| `gov-choose-my-notifications` | Choose which learniq notifications reach me, with quiet hours. | `partial`: the switches save; quiet hours never save |

## What changes

- The settings panel reads quiet hours from `GET /apps/openregister/api/notification-delivery-window` and saves them with `PUT` on the same path, sending the browser's IANA time zone.
- A 422 from OpenRegister shows its message next to the fields; the "not enforced yet" note is shown only when the endpoint answers 404 (an OpenRegister without the change).
- The board's sentence stays true: reminders with a deadline still arrive on time, which the `scholiq-notifications` lead-time rule already covers.

## Capabilities

### Modified capabilities

- `scholiq-notifications`: ADDED requirement naming the endpoint.

## Impact

- **Frontend**: `src/views/LearniqNotificationSettings.vue`, l10n.
- **Backend**: none. ADR-022: learniq keeps no preference store of its own.
- **Cross-repo**: none. OpenRegister's endpoint is on development.
