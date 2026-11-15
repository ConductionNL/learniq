---
kind: code
depends_on: []
---

# Proposal: timetabling-room-utilisation

## Summary

A facility manager or deputy head opens a report under Reports and sees, for a chosen period, how each room is used: hours in use against the hours the building is open, how full the room is (group size against capacity), and a grid per weekday and hour that shows when rooms stand empty or are all taken. Rooms can be filtered by kind and building. It answers space questions such as whether a third gym is needed, from lessons learniq already has.

## Why

Row `tt-room-utilisation` of planninq's matrix (`ConductionNL/planninq openspec/parity/capabilities.json`, planninq#665), owed to learniq ("Report how well rooms are used, to plan space."), `none`. Decision: build, two competitors rate yes.

- zermelo, yes: https://support.zermelo.nl/guides/medewerker/lokaalstatistiek "Bij de statistieken van de lokalen, krijgt u inzicht over de bezetting. Zo wordt zichtbaar hoe optimaal de lokalen worden gebruikt"; https://zermelo.nl/software, management information on "of het nodig is om extra gymzalen te huren".
- timeedit, yes: https://timeedit.com/platform/scheduling/reserve "Maximize the use of every room and resource with data-driven booking and occupancy insights"; https://timeedit.com/platform/scheduling/scheduling "Understand resource utilization".
- untis, partial: https://www.untis.at/produkte/webuntis/termin "Berichte helfen die Raumauslastung an der Schule auszuwerten" (module Termin).
- xedule, partial: https://support.xedule.nl/hc/nl/articles/37589260428434-Ruimtebeheer, dashboards for "Bezettingsgraad", "Capaciteit" and "Benutting" (an extra module).

## What learniq has today

Read at learniq `development` 8bb8401d.

- `Room` (`lib/Settings/learniq_register.json`, school-structure spec `openspec/specs/school-structure/spec.md:354`): `name`, `code`, `capacity`, `kind` (`classroom`, `lab`, `gym`, `auditorium`, `online`, `other`), `facilities`, `buildingCode`, `floor`.
- `Session.roomId` and the materialised `Session.durationMinutes` (:6376); `Cohort.learnerIds` gives group size.
- `ReportPeriod` holds `holidays` and `studyDays`.
- `TimetableConflictDetector` already compares a session's group size against `Room.capacity` for capacity overruns. No report looks at rooms over time.
- The Reports page (`src/manifest.json:286`) is the place for readings like this (D8).

## What this change builds

1. `RoomUtilisationService`: for a period and a set of rooms, per room the hours in use (non-cancelled sessions), the hours open (school opening hours per weekday times teaching days, minus holidays), the occupancy rate, the average fill (group size against capacity), and a weekday by hour grid of rooms in use.
2. A tenant setting for opening hours per weekday (default 08:00 to 17:00, Monday to Friday).
3. A "Room use" card and page under Reports, with filters on room kind and building, the room table and the grid, and CSV export.

## Out of scope

- Booking rooms outside lessons (deferred rows `tt-self-service-booking`, `tt-booking-rules`).
- A cancellation report (deferred row `tt-cancellation-report`); it could join this report card later.

## Affected projects

- [x] `learniq`: a service and route, a setting, a Reports page and card, l10n.

## Risks

- Sessions without a `roomId` (only a free-text `location`) are not counted. Mitigation: the report states how many lessons in the period had no room and links to them.
