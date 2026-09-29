# Design: timetabling-contact-hours

## Context

Three numbers are compared: owed (the plan), given (lessons held for the group) and attended (lessons each learner was in). Owed comes from `HourPlan` (change `timetabling-multi-year-hour-plan`): the lines for the cohort's programme and programme year, per course and period. Given comes from sessions: `Session.durationMinutes` (`lib/Settings/learniq_register.json:6376`, materialised calculation) for sessions of the cohort that are not `cancelled`. Attended comes from `AttendanceRecord.lesuren` (:15613, materialised), which is minutes attended divided by 60 for a record with status `present`, `late` or `left-early`.

## Sessions after D10

Under D10 and D25 the timetable moves to planninq (`timetableSession`, planninq change school-timetable-target, contract version 1), and learniq reads it through `OCA\Planninq\Event\TimetableSessionsQueryEvent`. `ContactHoursService` reads sessions through one private reader, `sessionsFor(cohortId, from, to)`, returning `{id, courseId, startsAt, endsAt, cancelled}`. Today it reads learniq `Session`; when learniq's `sessions-from-planninq` lands, only that reader changes (planninq's `status` gives `cancelled`, `startsAt` and `endsAt` give the duration, and `cohortId` is on the row when the deliverer resolved it). Attendance stays in learniq either way.

## Computation

`ContactHoursService::forPeriod(string $from, string $to, ?string $cohortId = null): array`

1. Cohorts: those with sessions in the window, or the one asked for.
2. Owed per (cohort, course): the active `HourPlan` of the cohort's programme for its intake year; sum of `contactHours` of lines with the cohort's `programmeYear`, prorated to the window: a line with a `periodCode` counts fully when its report period lies inside the window; a line with no period counts by the share of the school year's teaching weeks the window covers (school year from the cohort's `academicYear`, minus `ReportPeriod.holidays`).
3. Given per (cohort, course): sum of duration in hours of the cohort's non-cancelled sessions in the window with that `courseId`.
4. Attended per learner: sum of `lesuren` of the learner's records with status `present`, `late` or `left-early` whose session is in the window, one aggregated read per cohort (`x-openregister-aggregations`-style grouped sum through `ObjectService::findAll` with a `sessionId` in-list).
5. Shortfall: given below owed for a course, or attended below given by more than a threshold setting (default 10 percent) for a learner.

A cohort without a hour plan shows given and attended only, with "no hour plan" in the owed column.

## Page and route

- Reports card "Contact hours" (`src/manifest.json` Reports page `cards`, category `attendance`) opens `ContactHoursReport` (`/reports/contact-hours`, custom page): period picker (report periods and school years), cohort filter, a table per cohort (course, owed, given, difference, marked red when short), totals, and a learner drill-down. CSV export of both tables.
- `GET /api/reports/contact-hours?from=&to=&cohortId=`, `#[NoAdminRequired]`, allowed for `instructors`, `team-leads`, `compliance-officers` (check in the body, gate 7); a mentor who is only in `instructors` sees every cohort, as they do on the attendance pages today.

## Declarative versus imperative

| behaviour | path | reason |
|---|---|---|
| session duration, attended hours per record | declarative, existing calculations | already materialised |
| owed against given against attended over a window | imperative, `ContactHoursService` | a read across plans, cohorts, sessions and records with proration; ADR-031 exception for a cross-schema report that a per-object aggregation cannot express; stores nothing |

## Seed data

No schema changes. The MBO example set's cohort "MV2A" gets sessions for periods 1 and 2 with three cancelled lessons in "Engels", so the report shows a shortfall there, and attendance records where one learner attended 70 percent of "Marketing".

## As built (2026-09-28)

- Stacked on `timetabling-multi-year-hour-plan` (PR #1312): owed hours come from `HourPlanActivityService::forYear()`, which already resolves each group's active plan by programme and intake year.
- Given hours are read through `TimetableSourceResolver` for the window, so with planninq installed the report counts planninq's lessons (`status: cancelled` maps to `lifecycle: cancelled`); a planninq lesson without a `courseId` is matched to a course by its subject name.
- `ContactHoursReader` holds the register reads, `ContactHoursCalendar` the date arithmetic: a period line counts when its whole report period lies in the window; a line without a period counts by the share of teaching days (weekdays minus the report periods' holidays).
- The margin is the app setting `contact_hours_margin_percent`, default 10.
- The route opens on the ADR-023 action `report.contact-hours` (admin, instructors, team leads, compliance officers). The period picker is a from and to date; CSV export is built in the page.
