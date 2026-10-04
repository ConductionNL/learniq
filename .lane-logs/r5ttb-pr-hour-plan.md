## Scope

A programme gets an hour plan over its whole length: per course, year of the programme and period, the contact hours a group gets, with a norm per year. From the plans and this year's groups, learniq lists the teaching activities a school year needs, for the timetabling system that planninq stores the timetable of (D10). Learniq plans the education and places nothing in a week or a room.

Builds `openspec/changes/timetabling-multi-year-hour-plan` (planninq matrix rows `tt-multi-year-plan` and `tt-curriculum-to-activities`, planninq#665 and #669, learniq#1039; tenders Graafschap College TenderNed 271977 and Firda 414807, Xedule and Zermelo rate yes, evidence in the proposal).

## What changed

- Register: new `HourPlan` 0.1.0 (lines, periods, norms per year, lifecycle draft, active, archived), `Cohort` 0.1.1 to 0.2.0 with `programmeYear`, `info.version` to 0.32.0.
- `HourPlanActivationGuard` on `activate`: one active plan per programme and intake year, the refusal names the active plan.
- `HourPlanActivityService` derives the activities: a group in programme year N of school year Y reads the active plan of intake year Y minus (N minus 1). Teachers come from `SubjectTeacherAssignment`. Groups whose programme has no active plan for their intake are listed, so a missing plan does not read as nothing to schedule.
- `GET /api/hour-plans/activities?academicYear=` for instructors, team leads and compliance officers (check in the body), and the in-process ADR-041 query `HourPlanActivitiesQueryEvent` for integriq's rostering export.
- Pages: `HourPlanEditor` (`/hour-plans/:id`, the grid with totals per year against the norm, activate, archive, copy to next intake) and `HourPlanActivities` (`/teaching-activities`, menu Timetabling, CSV export). ProgrammeDetail gets an "Hour plans" list and the cohort list shows the programme year.
- The school year rollover raises `programmeYear` by one for a group that stays in its programme, and keeps the programme on the new group.
- The totals and shortfalls are not `x-openregister-calculations`: the calculation grammar has no sum over an array, so the editor computes them (`src/utils/hourPlan.js`, tested).
- MBO example set: `programmeYear` on every class, an active plan per programme and intake year with a class this year, a draft for the next Software developer intake. 74 new catalogue keys with Dutch values (marked AI-translated).

## Verified

- `TMPDIR=$PWD/.tmp COMPOSER_PROCESS_TIMEOUT=0 composer check:strict`: exit 0 (lint, phpcs, phpmd, psalm, phpstan clean; PHPUnit 2217 tests, 0 failures)
- `npm run lint`: exit 0; `npm run format`: exit 0; `npm run check:manifest`, `check:register`, `check:json-strict`, `check:menu-role-gates`: pass; `npm run check:schema-l10n`: exit 0 (2233, two under the baseline); `npm run check:l10n-js`: up to date
- `node --test tests/unit-js/hourPlan.test.mjs`: 5 of 5 pass
- `npx openspec validate timetabling-multi-year-hour-plan --strict`: valid
- `python3 scripts/example-sets/mbo.py --check`: up to date after regenerating
- hydra gates `run-hydra-gates.sh --base origin/development`: exit 8; gate 5, 7, 16, 47 and 101 pass, gate 25 standalone on this diff: "1 new endpoint, all covered". The failing gates are all inherited, see below.
- Playwright `tests/e2e/hour-plan.spec.ts` is written and not run: the shared instance on :8080 is off limits to build lanes.

## Inherited

Gate 3 (six `check()` methods in Wallet, LearningRecord and ReportCardPdf services), 25 and 49 (`ComplianceRollupController`), 53 (the gate's own script fails under the workspace `"type": "module"`), 55 (CohortDetail layout overlap), 60 (`FileAccountOutline` not in `src/icons.js`), 112 and 113 fail on development too and touch no file of this PR. `npm run test:js-unit` has one inherited failure (`openregisterSchemaRefs`, `Assessment` in LessonComposer and LessonPlayer).

No stacked base. The other changes of lane r5-timetabling-b (lesson notes #1250, room use, standby slots, visibility rules) also bump `info.version` to 0.32.0 and touch `lib/Settings/learniq_register.json`, the catalogue and the example set generators; the landing orders them.

🤖 Generated with [Claude Code](https://claude.com/claude-code)
