# Design: quiet hours go to OpenRegister's delivery window

## Context

- `LearniqNotificationSettings.vue` is the content of the user settings dialog (`src/App.vue:46`). It lists learniq's rules from `GET /apps/openregister/api/notification-preferences` and toggles each one with a `PUT` there. That part works.
- Its `fetchPreferences()` reads `data.quietHours` from the same response, which never carries it, and `saveQuietHours()` PUTs `{quietHours}` there, which is refused. The catch block turns every refusal into the hint "Quiet hours are not yet enforced by your Nextcloud instance".
- OpenRegister `NotificationDeliveryWindowController`: `GET` answers the stored window or `{enabled: false}`; `PUT` with `enabled: false` clears it, otherwise validates `start` and `end` (`HH:MM`), optional `timezone` (IANA) and `days` (0 to 6) and answers 422 on a bad value.

## Screen

Board `LqMeldingen`, dialog "Instellingen van learniq", tab "Meldingen":

- "Kies welke meldingen van learniq u wilt krijgen. Dit geldt alleen voor uw eigen account."
- One switch per notification with a one-line explanation (Inlevering binnen, Verzuimmelding, Roosterwijziging, Nieuw bestand in uw lesmap, Herinnering cijfers).
- "Stille uren": "Stel meldingen van learniq uit tijdens vaste uren. Herinneringen met een deadline komen op tijd binnen." A switch "Stille uren aanzetten", fields Begin and Einde.
- The board shows the note "Uw Nextcloud dwingt stille uren nog niet af. Uw keuze blijft bewaard en werkt zodra dat kan." That note is the fallback for an OpenRegister without the endpoint, not the normal state.

## Decisions

### D1: The delivery-window endpoint is the only store

Reading and writing go to `/apps/openregister/api/notification-delivery-window`. Nothing is kept in learniq or in browser storage.

### D2: The time zone comes from the browser

`Intl.DateTimeFormat().resolvedOptions().timeZone` is sent as `timezone`. Without it OpenRegister falls back to the server's zone, which is wrong for a user abroad.

### D3: The window is per user, not per app

OpenRegister stores one window per user for every app on the instance. The panel says so under the fields: "This applies to every app that sends notifications through Open Register." Hiding that would make a teacher think pipelinq still reaches her at night.

### D4: Which answer shows which message

| Answer | Panel |
|---|---|
| 200 | the saved values, no note |
| 422 | OpenRegister's `error` next to the fields, values kept |
| 404 | the board's "not enforced yet" note, values kept in the form only |
| other | "Saving failed. Try again." |
