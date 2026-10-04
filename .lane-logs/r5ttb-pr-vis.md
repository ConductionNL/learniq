## Scope

A school decides per role whose timetables people may open: a learner their own group only, or also their teachers and rooms; a teacher their own groups, or every group, teacher and room. A new Timetables page looks up the timetable of a group, a teacher or a room and offers only what the caller may open; `Session` gets its own read line so the object API does not get round it.

Builds `openspec/changes/timetabling-visibility-rules` (planninq matrix row `tt-visibility-rules`, planninq#665; Zermelo, Untis, Xedule and TimeEdit all rate yes, evidence in the proposal). Under D10 planninq stores the timetable and learniq renders it, so who sees which timetable is learniq's rule.

## What changed

- Register: new `TimetableVisibilityPolicy` 0.1.0 (one per school; defaults learners own groups, related teachers and rooms, teachers all), written by team leads and compliance officers. `Session` 0.1.2 to 0.2.0 gets an `authorization` block for `instructors`, `hr`, `compliance-officers`, `team-leads`: exactly the groups of the register-level `authorization.roles` rule, so under either reading of OpenRegister's fallback the object API stays staff-only and nothing staff reads today changes (D23: stricter, never looser). `info.version` to 0.32.0.
- `TimetableVisibilityService` decides (`own`, `related`, `all` per role and kind, from the caller's own cohorts); `TimetableDirectory` reads. Team leads, compliance officers and admins always see all.
- `GET /api/timetable/of?kind=cohort|teacher|room&id=` (403 "Your school does not let you see this timetable." when refused), `GET /api/timetable/of/options?kind=`, `GET /api/timetable/visibility-policy`. Checks in the body.
- Reads of `session` (task 1): `CohortTimetableView.vue` now reads through `/api/timetable/of?kind=cohort`. `AttendanceRegisterView.vue` is a teacher screen and keeps its object read. `LocalSessionTimetableSource` now reads without the caller's RBAC: the timetable endpoints decide access before they ask it (cohort membership for My timetable, an RBAC read of the cohort for the cohort page, the policy for other timetables). Without this a learner's own timetable would read no lessons under a staff-only `Session`. The other readers (`SessionWindowLoader`, `AttendanceWindowAggregator`, `PeerReviewController`, listeners) run for staff or as the system.
- Page `TimetableLookup` (`/timetables`, menu under My learning) with the policy editor for team leads and compliance officers on the same page (the learniq settings page is admin only). The week is a day list; MyTimetable is untouched.
- VO example set: a policy (learners own group, related teachers, all rooms). Mock register objects for gate 101. 37 new catalogue keys with Dutch values (marked AI-translated).

## Not done, and why

- The live check of task 1 (list `session` as a learner on an instance) was not run: build lanes may not touch the shared instance. The block above is written so the answer does not change what staff can read.
- Planninq's `timetableSession` stays readable by every signed-in user (school-timetable-target design). Once learniq reads from planninq, a learner could read any lesson through planninq's `GET /api/timetable/sessions`. Narrowing that read is planninq's to do and would also need planninq's query event handler to keep answering learniq's in-process reads; it is named here for the planninq lane, not changed.

## Verified

- `TMPDIR=$PWD/.tmp COMPOSER_PROCESS_TIMEOUT=0 composer check:strict`: exit 0 (lint, phpcs, phpmd, psalm, phpstan clean; PHPUnit 2247 tests, 0 failures)
- `npm run lint`: exit 0; `npm run format`: exit 0; `npm run check:manifest`, `check:register`, `check:json-strict`, `check:menu-role-gates`: pass; `npm run check:schema-l10n`: exit 0; `npm run check:l10n-js`: up to date
- `npx openspec validate timetabling-visibility-rules --strict`: valid
- `python3 scripts/example-sets/vo.py --check`: up to date after regenerating
- hydra gates `run-hydra-gates.sh --base origin/development`: exit 8; gate 5, 7, 8, 16, 47, 97 and 101 pass. The failing gates are all inherited, see below.
- Playwright `tests/e2e/timetable-visibility.spec.ts` is written and not run: the shared instance on :8080 is off limits to build lanes.

## Inherited

Gate 3 (six `check()` methods in Wallet, LearningRecord and ReportCardPdf services), 25 and 49 (`ComplianceRollupController`), 53 (the gate's own script fails under the workspace `"type": "module"`), 55 (CohortDetail layout overlap), 60 (`FileAccountOutline` not in `src/icons.js`), 112 and 113 fail on development too and touch no file of this PR.

No stacked base. The other changes of lane r5-timetabling-b (lesson notes #1250, hour plans #1312, room use #1354, standby #1366) also touch `lib/Settings/learniq_register.json` (all bump `info.version` to 0.32.0), `appinfo/routes.php`, `src/api/timetable.js`, the manifests and the catalogue; the landing orders them.

🤖 Generated with [Claude Code](https://claude.com/claude-code)
