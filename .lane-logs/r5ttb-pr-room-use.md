## Scope

A facility manager or deputy head opens "Room use" under Reports and sees, for a period, how many of the open hours each room is used, how full it is, and a weekday by hour grid of the share of rooms in use. Lessons without a room are counted and named, so missing data does not read as an empty room.

Builds `openspec/changes/timetabling-room-utilisation` (planninq matrix row `tt-room-utilisation`, planninq#665; Zermelo and TimeEdit rate yes, Untis and Xedule partial, evidence in the proposal).

## What changed

- `RoomUtilisationService` (with `RoomUseTally` for the running totals): hours open are the opening hours per weekday on teaching days, without the holidays of the report periods (and without study days when the school says rooms close then). Hours in use are lessons that were not cancelled, clipped to opening hours. Fill is group size against capacity. Nothing is stored.
- D10: lessons come from the current timetable source, planninq's school timetable when installed, learniq's Session otherwise. A planninq lesson names its room by `roomReference`, matched to `Room.code`.
- `GET /api/reports/room-use?from=&to=&kind=&building=` for instructors, team leads and compliance officers; `GET|PUT /api/reports/room-use/opening-hours`, written by team leads and compliance officers only (checks in the body). Opening hours default to Monday to Friday, 08:00 to 17:00.
- The opening hours are edited on the report page, not on the learniq settings page as the design said: that page is admin only, and team leads must be able to change them.
- Page `RoomUtilisationReport` (`/reports/room-use`) behind a "Room use" card under Reports (D8), role gated to staff: filters, the room table, the heat map, the roomless lessons with links, CSV export.
- No example rows: the VO set's lessons are one whole-day block per class in its own classroom, so the report already shows busy classrooms and empty gyms and labs. Extra gym lessons would overlap a class's day block and be flagged as double bookings by SessionConflictListener. 35 new catalogue keys with Dutch values (marked AI-translated).

## Verified

- `TMPDIR=$PWD/.tmp COMPOSER_PROCESS_TIMEOUT=0 composer check:strict`: exit 0 (lint, phpcs, phpmd, psalm, phpstan clean; PHPUnit 2209 tests, 0 failures)
- `npm run lint`: exit 0 (after the import-order fix in the second commit); `npm run format`: exit 0; `npm run check:manifest` and `check:menu-role-gates`: pass; `npm run check:schema-l10n`: exit 0; `npm run check:l10n-js`: up to date
- `node --test tests/unit-js/roomUse.test.mjs`: 4 of 4 pass
- `npx openspec validate timetabling-room-utilisation --strict`: valid
- hydra gates `run-hydra-gates.sh --base origin/development`: exit 8; gate 5, 7, 16 and 47 pass, gate 101 not applicable (no schema change). The failing gates are all inherited, see below.
- Playwright `tests/e2e/room-use.spec.ts` is written and not run: the shared instance on :8080 is off limits to build lanes.

## Inherited

Gate 3 (six `check()` methods in Wallet, LearningRecord and ReportCardPdf services), 25 and 49 (`ComplianceRollupController`), 53 (the gate's own script fails under the workspace `"type": "module"`), 55 (CohortDetail layout overlap), 60 (`FileAccountOutline` not in `src/icons.js`), 112 and 113 fail on development too and touch no file of this PR.

No stacked base. The other changes of lane r5-timetabling-b (lesson notes #1250, hour plans #1312, standby slots, visibility rules) also touch `appinfo/routes.php`, the manifests, `src/registry.js` and the catalogue; the landing orders them.

🤖 Generated with [Claude Code](https://claude.com/claude-code)
