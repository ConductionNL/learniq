# Proposal: a group teacher's dashboard lists only their own groups

## Why

Found testing a primary school on a clean install (2026-10-01). The group teacher of Groep 7 (`po-leerkracht-09`, group `instructors`) opened the teacher dashboard. "My cohorts", "My courses", "Sessions to mark" and "Assignments to grade" listed the whole school. Each widget asked OpenRegister for the first six rows of its schema with no filter.

Two lists also showed the wrong fields. The cohort list showed `programmeId`, a raw uuid, and `learnerCount`, which Cohort does not have. The session and assignment lists asked for `name` and `dueDate`, while Session and Assignment have `title` and `dueAt`.

## What changes

- A group teacher (primary role `instructor`) gets lists scoped to their own groups:
  - cohorts whose `teacherIds` lists them;
  - sessions and assignments whose `cohortId` is one of those cohorts;
  - courses those cohorts run: `Cohort.courseId`, plus `Programme.courseIds` of the cohort's programme.
- Coordinators, directors (`administration-managers`), team leads and admins keep the school-wide lists.
- `ManageListWidget` stays generic: the dashboard passes each list's filter through the `filter` prop. A list value goes out as `key[]=a&key[]=b`, which OpenRegister reads as an IN filter. An empty list matches nothing, so the widget asks the server nothing. A new `pending` prop holds the fetch while the dashboard works out the scope.
- The cohort list shows name, period and status. The session list shows `title`. The assignment list shows `title` and `dueAt`.

## Out of scope

- The two KPI tiles at the top of the teacher view (average engagement score, open engagement flags) still count school-wide.
- What a teacher may read: `Cohort`, `Session`, `Course` and `Assignment` still grant `instructors` school-wide. This change scopes the dashboard, not the register.
