# Design: timetabling-multi-year-hour-plan

## Context

Learniq describes what a programme contains (`Programme.courseIds`, `lib/Settings/learniq_register.json:4881`) and how it is assessed (`CurriculumPlan.components` and `periods`, `:5025`). It does not say how much teaching time each part gets in which year. Tenders from MBO colleges ask for that plan over several years (TenderNed 271977, 414807), and the timetabling systems start from it (Zermelo's lesson table, Xedule's meerjarenplanning to jaarplanning). Under D10 the timetable lives in planninq and is made by an outside system; learniq's job is the plan that feeds it.

## Data model

### `HourPlan` (new, slug `hour-plan`, 0.1.0)

| property | type | notes |
|---|---|---|
| `programmeId` | uuid, `$ref: Programme`, required | |
| `intakeYear` | string `YYYY-YYYY`, required | the school year a cohort starting this programme begins in; the plan covers that cohort's whole route |
| `durationYears` | integer 1 to 6, required | |
| `periodsPerYear` | array of `{periodCode, label}` | the period structure of every year, for example four periods; matches `ReportPeriod.periodCode` where the school uses report periods |
| `lines` | array of `HourPlanLine`, see below | |
| `yearNorms` | array of `{programmeYear, contactHours, totalHours}` | the norm per year, for example the MBO norm for guided education time |
| `lifecycle` | `draft`, `active`, `archived` | one `active` plan per (`programmeId`, `intakeYear`), checked by a guard |
| `tenant_id` | string, required | |

`HourPlanLine`: `courseId` (uuid, `$ref: Course`, required), `programmeYear` (integer, required), `periodCode` (string, nullable: null means spread over the whole year), `contactHours` (number, required), `otherHours` (number, default 0), `activityKind` (`lesson`, `practical`, `work-placement`, `self-study`, `exam`, default `lesson`), `note` (string, nullable).

Calculations (declarative, `x-openregister-calculations`): `totalsPerYear`, an array of `{programmeYear, contactHours, otherHours}` summed from `lines`; `shortfalls`, the years whose summed `contactHours` is below the year's norm.

Authorization: read `authenticated`; create and update `instructors`, `team-leads`, `compliance-officers` (as `Programme`).

### `Cohort` (0.1.1 to 0.2.0)

- `programmeYear` (integer, nullable): the year of its programme the cohort is in during `academicYear`. The rollover wizard (`RolloverWizard`, change school-year-rollover) raises it by one when it moves a cohort up.

## Activity list

`HourPlanActivityService::forYear(string $academicYear): array` reads cohorts with `academicYear` equal to the argument and a `programmeId` and `programmeYear`, finds the `active` plan for the programme whose `intakeYear` is `academicYear` minus (`programmeYear` minus 1), and returns one row per matching line: `cohortId`, cohort name, `courseId`, course name, `periodCode`, `contactHours`, `otherHours`, `activityKind`, teacher ids from `SubjectTeacherAssignment` (`cohortId`, `courseId`). Nothing is stored; the list is derived on every read.

- Page `HourPlanActivities` (`/hour-plans/activities`, custom page with a year picker, a table grouped by cohort, and CSV export through the table's export action).
- Route `GET /api/hour-plans/activities?academicYear=YYYY-YYYY`, `#[NoAdminRequired]`, readable by `instructors`, `team-leads`, `compliance-officers` (check in the body, gate 7).
- A thin ADR-041 event `OCA\Learniq\Event\HourPlanActivitiesQueryEvent` with a result slot, so integriq's rostering export can read the list in process without a loopback call; learniq answers it with the same service.

## Plan editor

`ProgrammeDetail` (`src/manifest.d/learning.json:1422`) gets a widget "Hour plans" listing the programme's plans per intake year with status, and a custom page `HourPlanEditor` (`/hour-plans/:id`): rows are the programme's courses, columns are years and periods, cells are contact hours; the footer shows totals per year against the norm and marks a shortfall. Actions: activate, archive, "Copy from the previous intake" (creates a draft with the same lines for the next `intakeYear`).

## Declarative versus imperative

| behaviour | path | reason |
|---|---|---|
| `HourPlan` lifecycle, one active plan per intake | declarative lifecycle, `requires` guard `HourPlanActivationGuard` | guard: a uniqueness rule across rows, the ADR-031 lifecycle guard exception |
| totals and shortfalls | declarative calculations | derived from the row's own lines |
| activity list | imperative, `HourPlanActivityService` | a read across cohorts, plans and teacher assignments; stores nothing |
| editor grid | custom page | a matrix editor the generic form cannot render |

## Seed data

MBO example set (Voorbeeldcollege Vaartveld): programme "Medewerker marketing en communicatie" (3 years), `HourPlan` for intake `2026-2027`, four periods per year, lines for "Nederlands", "Engels", "Marketing", "Communicatie", "Keuzedeel" and "BPV" (`work-placement`), year norms of 1000 hours with 700 contact hours; cohort "MV2A" with `programmeYear: 2`. The plan for intake `2025-2026` is `active` so the MV2A activities list has rows.

## Open points

- Whether `yearNorms` should default from a national norm per level; left to the school because the norms differ per programme and change by law.
