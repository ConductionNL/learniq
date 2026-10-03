# Design: timetabling-enrolment-forecast

## Context

The inputs exist in learniq: current cohorts with their programme and size, subject choices for next year (`SubjectChoice.academicYear`, `selectedElectiveCourseIds`), placed applications from admission rounds. `Cohort.programmeYear` comes from `timetabling-multi-year-hour-plan`, hence the dependency. Nothing reads them forward.

## Data model

### `EnrolmentForecast` (new, slug `enrolment-forecast`, 0.1.0)

| property | type | notes |
|---|---|---|
| `name` | string, required | "Voorjaarsprognose 2027-2028 basis" |
| `targetYear` | string `YYYY-YYYY`, required | |
| `rates` | array of `{programmeId, programmeYear, upRate, repeatRate, leaveRate}` | fractions that add up to 1; for the last programme year, `upRate` means graduating |
| `intake` | array of `{programmeId, expected}` | first-year intake; when empty, placed applications of admission rounds for `targetYear` are used |
| `targetGroupSize` | integer, default 28 | |
| `result` | object, read-only | last computed result, with `computedAt` |
| `lifecycle` | `draft`, `final` | |
| `tenant_id` | string, required | |

Authorization: read, create and update `team-leads`, `compliance-officers`; read `instructors`.

## Computation

`EnrolmentForecastService::compute(array $forecast): array`, stored in `result`:

1. For every cohort of the current year with `programmeId` and `programmeYear`: learners moving up land in programme year + 1 of the same programme, repeaters stay in the same programme year, leavers leave, by the scenario's rates for that programme year (default rates from last year's rollover, when the rollover recorded counts, else 0.9, 0.05, 0.05).
2. First year: `intake.expected`, or the count of placed applications for `targetYear`.
3. Per subject: for learners with an approved `SubjectChoice` for `targetYear`, count by `selectedElectiveCourseIds`; for learners without one, apply the share of that course among the choices made so far, labelled estimated.
4. Groups needed per programme year and subject: learners divided by `targetGroupSize`, rounded up.

A route `POST /api/enrolment-forecasts/{id}/compute` runs it (staff groups above, check in the body, gate 7).

## Page

`EnrolmentForecastView` (`/reports/enrolment-forecast`, a card under Reports, category progress): scenario picker and "Copy scenario", an editable rates grid per programme and programme year, an intake field per programme, and result tables (programme year, subject) with learners and groups needed, estimated figures marked. CSV export.

## Declarative versus imperative

| behaviour | path | reason |
|---|---|---|
| scenario lifecycle | declarative | |
| forecast | imperative, `EnrolmentForecastService` | a computation across cohorts, choices and applications; ADR-031 exception for a cross-schema derived report; writes only the scenario's own `result` |

## Seed data

VO example set (Esdoornveen): scenario "Voorjaarsprognose 2027-2028" with rates for havo 3 to 5 and vwo 3 to 6, intake 140 for year 1, approved subject choices for half of havo 3, so the per-subject table has both counted and estimated figures.

## As built (2026-09-28)

- Stacked on `timetabling-multi-year-hour-plan` (PR #1312), which adds `Cohort.programmeYear`; a programme's length is the longest active hour plan's `durationYears`, else the highest programme year a group is in. Movers out of the last year finish and are not counted.
- `EnrolmentForecastReader` holds the register reads; placed applications are those `placed` or `converted` in admission rounds of the target year; approved choices are `approved` or `locked`.
- The subject table counts approved choices per programme and next programme year and estimates the learners without a choice from each subject's share among the choices made so far; estimated rows are marked.
- The route opens on the ADR-023 action `report.enrolment-forecast` (admin, team leads, compliance officers).
- The VO example set gains `programmeYear` on its groups (the leerjaar) and the scenario "Voorjaarsprognose 2026-2027" (the set's next year), with rates for havo 3 to 5 and vwo 3 to 6 and 140 in the brugklas; the result is computed on first use. The brugklas programme `hv` has only year 1 in this set, so its movers leave the forecast; a school with a separate brugklas programme adds rates or an intake for havo 2 and vwo 2.
