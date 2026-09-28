# Design: timetabling-room-utilisation

## Context

Rooms and lessons exist: `Room` (school-structure, `openspec/specs/school-structure/spec.md:354-366`), `Session.roomId` and the materialised `Session.durationMinutes` (`lib/Settings/learniq_register.json:6376`). Group size is `Cohort.learnerIds` length, the figure `TimetableConflictDetector` already uses for capacity overruns. What is missing is a reading over a period.

## Sessions after D10

The service reads lessons through the timetable's session reader. After learniq's `sessions-from-planninq`, planninq rows carry `roomReference` (the school's room code); the reader maps it to a learniq `Room` by `Room.code`. Rooms stay learniq objects.

## Computation

`RoomUtilisationService::forPeriod(string $from, string $to, array $filters): array`

- Rooms: all rooms of the tenant, filtered by `kind` and `buildingCode`.
- Open hours per room: the sum over teaching days in the window (weekdays with opening hours, minus `ReportPeriod.holidays` of the report periods that cover the window, minus `studyDays` when the setting "rooms closed on study days" is on) of that weekday's opening hours.
- Hours in use: sum of `durationMinutes` / 60 of non-cancelled sessions with that `roomId` in the window, clipped to opening hours.
- Occupancy: hours in use / open hours.
- Fill: average over those sessions of group size / `capacity` (sessions of rooms without capacity are left out of the fill).
- Grid: per weekday and clock hour, the share of filtered rooms in use.
- Unassigned: the count of non-cancelled sessions in the window without `roomId`.

## Setting

`OpeningHours` on the tenant: per weekday `{opens, closes}` or closed, and `closedOnStudyDays`. Stored in app config through `SettingsController`, editable by `team-leads` and `compliance-officers` on the learniq settings page.

## Page and route

- Reports card "Room use" (category quality, `src/manifest.json` Reports `cards`) opens `RoomUtilisationReport` (`/reports/room-use`): period picker, filters, a table (room, kind, building, capacity, hours in use, open hours, occupancy, fill), the grid as a heat map, the unassigned count with a link to those sessions, and CSV export.
- `GET /api/reports/room-use?from=&to=&kind=&building=`, `#[NoAdminRequired]`, allowed for `team-leads`, `compliance-officers` and `instructors` (check in the body, gate 7).

## Declarative versus imperative

| behaviour | path | reason |
|---|---|---|
| occupancy over a period | imperative, `RoomUtilisationService` | a read across rooms, sessions, cohorts and periods with clipping to opening hours; ADR-031 exception for a derived cross-schema report; stores nothing |
| opening hours | app config setting | a tenant setting, not a domain object |

## Seed data

No schema changes. The VO example set's rooms get a week of sessions in which the two gyms run at over 90 percent and the labs under 40 percent, so the report shows the contrast.
