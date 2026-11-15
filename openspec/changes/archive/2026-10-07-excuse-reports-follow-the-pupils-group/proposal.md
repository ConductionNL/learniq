# Proposal: an absence report reaches the pupil's group teachers and school-wide staff

## Why

Found testing a primary school on a clean install (2026-10-01). A group teacher (group `instructors`) opened the absence reports and saw every report in the school. The read rule on `ExcuseRequest` granted `instructors` without a condition. Coordinators and directors were not in the rule at all, so they saw nothing unless they were admin.

Ruben decided (2026-10-01): a teacher sees the reports of pupils in the groups they teach. Coordinators and directors (`administration-managers`) see every report in the school.

## What changes

- `ExcuseRequest` gets `teacherIds`: the Nextcloud user ids of the teachers of the pupil's current groups. `ExcuseRequestOwnerStamp` fills it on every write, from `Cohort.teacherIds` and `Cohort.teacherAssignments` of each current cohort whose `learnerIds` lists the pupil. A client value is never kept.
- The read rule replaces plain `instructors` with `instructors` matched on `teacherIds` containing the caller, the same `$contains` shape `AssessmentResult` uses. `coordinators` and `administration-managers` read school-wide. `compliance-officers` stay.
- The update rule (which the `approve` and `reject` transitions run under) gets the same teacher match, plus `coordinators`: the attendance spec says a coordinator approves or rejects. Directors read but do not decide.
- `create` adds `coordinators`, so a coordinator can record a report a parent phoned in.
- A repair step, `BackfillExcuseRequestTeachers`, stamps `teacherIds` on reports written before this change. It is idempotent.
- `info.version` moves up one patch version so the register re-imports.

## Out of scope

- Which cohorts a teacher may read. `Cohort` still grants `instructors` school-wide; the teacher dashboard scopes its own lists (separate change).
