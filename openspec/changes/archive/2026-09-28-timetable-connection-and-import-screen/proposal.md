---
kind: code
depends_on:
  - data-exchange-to-integriq
  - sessions-from-planninq
---

# Proposal: timetable-connection-and-import-screen

## Summary
Three follow-ups to the timetable and SWV exchange work. First, `connections.json` declared the timetable connection unavailable; its status now depends on whether planninq is installed, with integriq present. Second, `POST /api/timetable/imports` (learniq #1157) had no screen; the timetable conflicts page now has an import button for anyone who holds `exchange.request`. Third, the group code to cohort map per rostering system and `swv_receiver_id` could only be set in app config; both now have a section on the admin page.

## Motivation
- TRACKER-R2 "ROUND 3 FINAL" follow-ups: "connections.json timetable connection", "group code to cohort mapping per source", "swv_receiver_id has no settings screen", "`POST /api/timetable/imports` has no screen".
- D10 (planninq owns the timetable) and D7/D25 (integriq carries the exchanges, learniq keeps the gates).

## Affected Projects
- [x] Project: `learniq`: `lib/Settings/connections.json`, `ConnectionReportService`, `ConnectionReportJob`, `SettingsController`, `TimetableImportController` (a new `access` action), `PlanninqTimetableImport` (kept map fallback), `TimetableExchangeSettings` and `TimetableExchangeSettingsController` (new), `appinfo/routes.php`, `TimetableExchangeSettingsSection.vue`, `TimetableImportDialog.vue`, `TimetableConflictQueue.vue`, `AdminRoot.vue`, en and nl catalogue entries, `docs/installation.md`, tests.

## Scope

### In Scope
- The timetable row becomes `reportedOnly`. Learniq reports `configured` when planninq is enabled and `unavailable` otherwise, from the daily report job and on a settings save. The report itself only goes out when integriq is there.
- `GET /api/timetable/imports/access` answers `{canImport, planninq}`. The conflicts page shows **Import a timetable** to a holder of `exchange.request`, and enables it where planninq takes the delivery.
- `GET` and `PUT /api/admin/timetable-exchange` (admin setting) keep `timetable_group_maps` (`{rosterSource: {groupCode: cohortId}}`) and `swv_receiver_id`.
- An import request without its own `groupMap` sends the kept map of its rostering system; a posted map still wins.

### Out of Scope
- The teacher map (`teacherMap`): not in the brief.
- Integriq's registry itself: it has no field for "requires app X". So learniq reports the row, which the registry already supports (`reportedOnly` and the status report event).

## Approach
Code only; no register schema changes.

## New Dependencies
None.

## Impact
The Integrations page stops calling the timetable "no longer imported", and admins stop needing `occ config:app:set` for the two settings.
