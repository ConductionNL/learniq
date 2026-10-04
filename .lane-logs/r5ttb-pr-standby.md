## Scope

A coordinator plans standby hours: per teacher, the weekly slots in which they are on call to cover a colleague. When someone assigns a substitute, the dialog lists the teachers on standby at that time first, then the teachers who work that day and are free, instead of asking for a user id typed by hand. Learniq suggests; a person chooses.

Builds `openspec/changes/timetabling-standby-slots` (planninq matrix row `tt-standby-slots`, planninq#665; Zermelo and Xedule rate yes, Untis partial, evidence in the proposal). Substitution stays learniq's under D10.

## What changed

- Register: new `StandbySlot` 0.1.0 (teacher, weekday or one date, time window, location, validity), `info.version` to 0.32.0. Every signed-in user reads standby (a staff room board); team leads and compliance officers plan it.
- `SubstitutionCandidateService`: standby teachers whose slot covers the lesson (day, validity, overlap, same location when both name one), then staff whose working days include that weekday and who have no lesson then, then a standby teacher who does have a lesson then with "has a lesson then". The lesson's own teachers are never listed. Lessons come from the current timetable source (planninq when installed, D10).
- `GET /api/substitution/candidates?sessionId=` for the lesson's cohort teachers and `admin` or `coordinators` (the callers `SessionChangeGuard` allows; check in the body), and `GET /api/standby/mine?from=&to=` for the caller's own standby blocks (`StandbyCalendar`).
- `SubstitutionModal`: the free-text user id field is an NcSelect with `inputLabel`, options in the server's order with their reason. It stays taggable, so any colleague can still be typed. `SessionChangeGuard` still checks the substitution.
- `StandbyPlanning` (`/standby`, menu Timetabling, coordinators and admins) with `StandbySlotDialog`; `MyTimetable` draws the caller's standby blocks in the week. The standby comes from its own route instead of an extra field on `/api/timetable/mine`, so that endpoint is unchanged.
- VO example set: twelve standby slots (two teachers in the second hour of every weekday, one on Wednesday hour 5 and Thursday hour 6) at the main location. Mock register objects for gate 101. 40 new catalogue keys with Dutch values (marked AI-translated).

## Verified

- `TMPDIR=$PWD/.tmp COMPOSER_PROCESS_TIMEOUT=0 composer check:strict`: exit 0 (lint, phpcs, phpmd, psalm, phpstan clean; PHPUnit 2212 tests, 0 failures)
- `npm run lint`: exit 0; `npm run format`: exit 0; `npm run check:manifest`, `check:register`, `check:json-strict`, `check:menu-role-gates`: pass; `npm run check:schema-l10n`: exit 0; `npm run check:l10n-js`: up to date
- `node --test tests/unit-js/standby.test.mjs`: 3 of 3 pass
- `npx openspec validate timetabling-standby-slots --strict`: valid
- `python3 scripts/example-sets/vo.py --check`: up to date after regenerating
- hydra gates `run-hydra-gates.sh --base origin/development`: exit 8; gate 5, 7, 8, 16, 47 and 101 pass. The failing gates are all inherited, see below.
- Playwright `tests/e2e/standby.spec.ts` is written and not run: the shared instance on :8080 is off limits to build lanes.

## Inherited

Gate 3 (six `check()` methods in Wallet, LearningRecord and ReportCardPdf services), 25 and 49 (`ComplianceRollupController`), 53 (the gate's own script fails under the workspace `"type": "module"`), 55 (CohortDetail layout overlap), 60 (`FileAccountOutline` not in `src/icons.js`), 112 and 113 fail on development too and touch no file of this PR.

No stacked base. The other changes of lane r5-timetabling-b (lesson notes #1250, hour plans #1312, room use #1354, visibility rules) also touch `lib/Settings/learniq_register.json` (all bump `info.version` to 0.32.0), `appinfo/routes.php`, `MyTimetable.vue` (#1250 too), the manifests and the catalogue; the landing orders them.

🤖 Generated with [Claude Code](https://claude.com/claude-code)
