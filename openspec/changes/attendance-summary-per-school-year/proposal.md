# Proposal: an attendance summary per learner per school year

## Why

Ruben (2026-10-02): the parent's child page needs KPI cards: absence this school year (with and without permission), late arrivals (how often, how many minutes) and absence without permission. Counting that from every `AttendanceRecord` on each page view is slow and repeats the counting rules in every place that shows them. The parent portal also cannot read `AttendanceRecord` across a whole year in one cheap call.

## What changes

- A new read model, `AttendanceSummary` (slug `attendance-summary`): one row per learner per school year with `absentDays`, `absentAuthorisedDays`, `absentUnauthorisedDays`, `lateCount`, `lateMinutes` and `updatedAt`, plus `learnerId`, `learnerRef`, `schoolYear`, `teacherIds` and `tenant_id`.
- Counting rules:
  - A school year runs from 1 August to 31 July, written `2025-2026` like `Cohort.academicYear`.
  - The day of a record is the date of its lesson (`Session.startsAt`), or of `markedAt` when the lesson cannot be read.
  - A date with at least one absence is an absent day. It counts as without permission when at least one absence that day is `absent-unexcused`, otherwise as with permission. So `absentDays` is always the sum of the other two. In a primary school, with one register a day, that is the day's mark. In a secondary school a missed lesson makes the date an absent day; lesson hours stay with the leerplicht 16-hour check.
  - Every `late` record counts once. Its minutes come from `lateMinutes`, or from the lesson length minus `minutesAttended` for records from before `lateMinutes` existed.
  - `left-early` is not counted.
- `AttendanceSummaryListener` reacts to every `AttendanceRecord` create, update and delete and defers the recount to `AttendanceSummaryRecomputeJob` (ADR-078). The job recounts the learner's whole school year, so it does not drift, and saves only when a number changed.
- `BackfillAttendanceSummaries`, a repair step, counts every learner with records. It saves nothing on a second run.
- Read rules: the pupil's group teachers (`teacherIds`, stamped by the server from `PupilGroupTeachers`), `coordinators`, `administration-managers` and `compliance-officers`. Nobody writes a row through the API: the schema grants no `create` or `update`, and the server saves with the system identity as owner.
- The primary school example set ships the summaries for 2025-2026, counted with the same rules, and its late records carry `lateMinutes`.

## Out of scope

- Showing the cards in the parent portal (lane lq-record reads this schema by `learnerRef`).
- Part-day (half-day) counting.
