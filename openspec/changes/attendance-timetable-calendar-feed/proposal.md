---
kind: code
---

# Read your timetable in your normal calendar app

## Why

A learner or teacher sees their timetable only inside learniq (`src/views/MyTimetable.vue`). Five of six competitors let people read the timetable in the calendar they already use. `TimetableController::mine` (`lib/Controller/TimetableController.php:121`) already resolves the caller's sessions, substitutions and cancellations for a window, so the missing half is a way to hand those to a calendar. The planninq matrix carries the same capability as the sibling row `sib-learniq-att-timetable-in-my-calendar` (owned by learniq), so this change covers both.

The rows share one screen or service, so they are one change.

### Matrix rows (`learniq` `openspec/parity/capabilities.json`)

| row | capability | Today |
|---|---|---|
| `att-timetable-in-my-calendar` | Read your timetable in your normal calendar app. | `partial`: `partial`: `TimetableProjector` projects sessions for the app's own timetable page; there is no calendar export or subscription |
| `sib-learniq-att-timetable-in-my-calendar` | Read your timetable in your normal calendar app. | `no`: `no` in planninq, owned by learniq: same capability, matrix row in the planninq product |

### Demand

- `att-timetable-in-my-calendar`: no demand row.
- `sib-learniq-att-timetable-in-my-calendar`: no demand row.

### Competitors rated yes

- `att-timetable-in-my-calendar`, moodle: "source read at moodle/moodle v5.2.3: public/calendar/export.php:49 + public/calendar/export_execute.php:27 (public/lang/en/calendar.php:149 'Export calendar'); each user gets an iCal URL with an auth token to subscribe in their ow"
- `att-timetable-in-my-calendar`, moodle-workplace: "read 2026-09-26: https://docs.moodle.org/502/en/Calendar_export; 'In Google Calendar's Other calendars menu, choose Add by URL and supply the URL generated'. Workplace includes this Moodle LMS feature: https://moodle.com/solutions"
- `att-timetable-in-my-calendar`, ilias: "source read at ILIAS-eLearning/ILIAS v11.4: lang/ilias_en.lang:8239,8289,8480-8481 (iCal URL subscription, also for Google Calendar); the personal calendar is exposed as an iCal feed. Reached on: Calendar > Subscribe |||."
- `att-timetable-in-my-calendar`, totara: "read 2026-09-26: https://totara.help/docs/export-the-calendar; 'Get calendar URL (for a link)' or an .ics download for external calendar apps."
- `att-timetable-in-my-calendar`, ispring-learn: "read 2026-09-26: https://ispringhelpdocs.com/ispring-learn/how-to-sync-calendars-62853286.html; 'Go to Trainings and click Sync Calendar . Select a calendar' with Google, Outlook, Apple and other calendars by link"
- `sib-learniq-att-timetable-in-my-calendar`, zermelo: "docs read 2026-09-27: https://support.zermelo.nl/guides/leerling-ouder/ical-link-voor-je-agenda 'importeer je je afspraken van school in je eigen agenda met een iCal-link' for the current and next week ; docs read 2026-09-27: http"
- `sib-learniq-att-timetable-in-my-calendar`, xedule: "docs read 2026-09-27: https://support.xedule.nl/hc/nl/articles/37415902430738-Beheer-Configuratie-MyX 'Roosters exporteren als ICS toestaan voor anonieme gebruikers / medewerkers / studenten. Vinkje staat standaard aan' ; https://"
- `sib-learniq-att-timetable-in-my-calendar`, timeedit: "docs read 2026-09-27: https://timeedit.com/platform/scheduling/viewer 'Calendar Feeds: Subscribe to schedules via iCal, Google Calendar, and Outlook. Auto-sync. Filtered feeds' ; https://www.academy.timeedit.com/product-updates/ne"

## What Changes

- Add a subscription feed: `GET /api/timetable/feed/{token}.ics` returns the caller's sessions from two weeks back to twelve weeks ahead as iCalendar events, with cancelled lessons as `STATUS:CANCELLED` and substitute teachers in the description.
- Add a Subscribe in your calendar action on the timetable page that shows the feed URL (and a webcal link), with Reset link to revoke it.
- Store one revocable random token per user; the feed is served by token, not by session, so calendar apps can fetch it.

## Capabilities

### New Capabilities

- `timetable-calendar-feed`

### Modified Capabilities

- None in delta form.

## Impact

- **Backend**: `TimetableFeedController` (public page, token auth), an iCalendar writer, one user preference for the token; route in `appinfo/routes.php`.
- **Frontend**: the Subscribe action on `MyTimetable.vue`, in its own dialog file.
- **Security**: a bearer token in a URL is a known trade-off for calendar subscriptions; tokens are 32 bytes random, stored hashed, revocable, and the feed is rate limited. Hydra gates 5, 7 and 30.
- **Cross-row**: the online meeting link of `timetabling-online-lesson-link` appears in the event URL when both are built.
