---
kind: code
depends_on: []
---

# Proposal: timetabling-display-screens

## Summary

A school shows today's lessons and today's changes on screens in the hall: which group has what, where, and which lessons are cancelled or moved. An administrator creates a display screen for a location, chooses what it shows, and gets a secret address for the screen's browser. The page refreshes itself, needs no signed-in user, and never shows a learner's name or a reason for a change.

## Why

Row `tt-display-screens` of planninq's matrix (`ConductionNL/planninq openspec/parity/capabilities.json`, planninq#665), owed to learniq ("Show today's timetable and its changes on screens in the building."), `none`. Decision: build, two competitors rate yes.

- untis, yes: https://help.untis.at/hc/de/articles/360015500660 "In den Monitoransichten können Sie folgende Formate für die automatische Darstellung von aktuellen Informationen/Daten an den Monitoren Ihrer Bildungseinrichtung definieren: Vertretungen, Aktivitäten, Tagesübersicht".
- timeedit, yes: https://timeedit.com/platform/scheduling/viewer "Digital Signage & Feeds: Display schedules on campus screens and calendars"; https://www.academy.timeedit.com/product-updates/213065497 "error pages will automatically reload every 5 minutes to better support unattended public displays".
- zermelo, partial: https://support.zermelo.nl/guides/applicatiebeheerder/verouderd-koppeling-met-overige-externe-partijen, screens through third parties over the API.
- xedule, partial: https://support.xedule.nl/hc/nl/articles/36898315959314-Export-My-Xedule, "De Lichtkrant - Rooster(wijzigingen) is een extra module".

Learniq renders the school timetable under D10; planninq stores it and has no timetable page (school-timetable-target, out of scope there).

## What learniq has today

Read at learniq `development` 8bb8401d.

- `TimetableProjector::todaysChanges()` (`lib/Service/TimetableProjector.php:164`) already selects the same-day cancellations and substitutions for the "Today's changes" panel of `MyTimetable.vue` (:72-80).
- `SessionsToday` (`src/manifest.d/learning.json:2531`, change school-year-shape) is a signed-in staff index of today's sessions.
- `Vestiging` (`lib/Settings/learniq_register.json:25107`) is a school location; `Cohort.locationId` points at it; `Room` has `buildingCode`.
- Public, token-free routes exist for verification (`CredentialVerifyController`, `LearningRecordShareVerifyController` with `#[PublicPage]`). Nothing shows the timetable without a signed-in user.

## What this change builds

1. A `DisplayScreen` schema: name, location, the rooms or groups it covers, what it shows (today, today and tomorrow, changes only), and a hashed secret.
2. An admin page to create a screen, show its address once, and revoke or renew it.
3. A public page `/apps/learniq/display/{token}` and its JSON `GET /api/public/display/{token}`: today's lessons for the screen's scope with time, group, subject, room and teacher code, and changes marked as cancelled, other teacher or other room. It refreshes every minute and keeps showing the last good data with a notice when it cannot reach the server.

## Out of scope

- Announcements and news on the screen (portaliq, D1).
- Playlists, video and other signage features; the page is one full-screen view a signage player or a browser can show.

## Affected projects

- [x] `learniq`: register (new `DisplayScreen`), a public controller and page, an admin page, seed data, l10n.

## Risks

- A public page leaks personal data. Mitigation: the projection for a screen is a separate, minimal shape (no learner, no user id, no free-text reason); the token is 32 random bytes stored as a hash; a revoked token answers 404.
