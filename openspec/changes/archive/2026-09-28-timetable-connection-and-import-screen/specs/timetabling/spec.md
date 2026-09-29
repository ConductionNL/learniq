# Timetabling Specification

## ADDED Requirements

### Requirement: The timetable connection is available when planninq and integriq are installed
The timetable row in `lib/Settings/connections.json` MUST NOT be declared unavailable. It MUST be `reportedOnly`, and learniq MUST report its status to integriq: `configured` when planninq is enabled, `unavailable` with a reason naming planninq otherwise. The report MUST be recorded by the daily connection report job and by an admin settings save.

#### Scenario: Planninq is installed
- **GIVEN** planninq is enabled and integriq is installed
- **WHEN** the daily connection report runs
- **THEN** the timetable row is reported `configured`

#### Scenario: Planninq is missing
- **GIVEN** planninq is not enabled
- **WHEN** the daily connection report runs
- **THEN** the timetable row is reported `unavailable`, and the message says planninq is not installed

### Requirement: The timetable page offers the import to whoever may request an exchange
`GET /api/timetable/imports/access` MUST answer whether the signed-in user holds `exchange.request` and whether planninq takes a delivery. The timetable conflicts page MUST show an import button only to a user who holds the right, MUST enable it only where planninq takes the delivery, and MUST post the chosen rostering system to `POST /api/timetable/imports`.

#### Scenario: An administration manager opens the timetable conflicts
- **GIVEN** a user in `administration-managers` on an instance with planninq
- **WHEN** they open the timetable conflicts page
- **THEN** they see an enabled "Import a timetable" button

#### Scenario: A coordinator without the right
- **GIVEN** a coordinator the action matrix does not grant `exchange.request`
- **WHEN** they open the timetable conflicts page
- **THEN** no import button shows

### Requirement: An administrator keeps the group code maps and the SWV receiver on the admin page
The admin page MUST have a section that reads and writes, per rostering system (`roster-zermelo`, `roster-untis-oneroster`, `roster-xedule`, `roster-timeedit`), the map from group code to learniq cohort, and the `swv_receiver_id` the SWV hand-off sends. The endpoints MUST be admin settings. A receiver that is not a lowercase hyphenated name, or an unknown rostering system, MUST be refused with a reason.

#### Scenario: Saving a map for Zermelo
- **GIVEN** an administrator on the admin page
- **WHEN** they map Zermelo group `4H1` to a cohort and save
- **THEN** the map reads back for `roster-zermelo`, with empty rows dropped

### Requirement: An import without a posted map uses the kept map
A timetable import request without its own `groupMap` MUST send the kept map of its rostering system to integriq, and MUST scan that map's cohorts for conflicts. A request that posts a map MUST send the posted map.

#### Scenario: Importing from Zermelo without a map
- **GIVEN** the administrator keeps `4H1` to a cohort for Zermelo
- **WHEN** an import for `roster-zermelo` is requested without a map
- **THEN** integriq receives the kept map
