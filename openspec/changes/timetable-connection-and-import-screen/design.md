# Design: timetable-connection-and-import-screen

## Connection status
Integriq's resolver (D4 rules) has no "requires app" field, and the row lives in integriq. So the row is declared `reportedOnly: true`, and learniq sends `ConnectionStatusReportedEvent`:

| planninq enabled | status | message |
|---|---|---|
| yes | configured | planninq and integriq are installed |
| no | unavailable | planninq is not installed |

Integriq's presence needs no check: without integriq the event class does not exist and nothing is reported, and the row does not exist either. `observeTimetable()` is public and is called by `ConnectionReportJob` and by the settings save, before `reportObservations()`, so the wallet report tests keep their event counts.

## Import button
`GET /api/timetable/imports/access` uses `ActionAuthService::can(user, 'exchange.request')` (the non-throwing sibling of the `requireAction` that `create()` calls), plus `PlanninqTimetableImport::applies()`. The dialog lives in `src/dialogs/` (modal isolation) and posts only `rosterSource`. The server fills in the map.

## Settings
`TimetableExchangeSettings` owns both keys. `swv_receiver_id` keeps the key that `SupportRequestSubmitHandler::RECEIVER_CONFIG_KEY` reads. Sources are integriq's Source row ids (`PlanninqTimetableImport::VENDOR_SOURCES`, now public, and a test keeps the two lists equal). The map fallback lives in `PlanninqTimetableImport::scope()`, so the delivery and the conflict scan both see it. TimetableImportController already sits at the coupling limit, so the fallback is not added there.
