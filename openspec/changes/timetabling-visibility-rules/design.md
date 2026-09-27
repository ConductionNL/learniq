# Design: timetabling-visibility-rules

## Context

The only timetable a learner has is their own (`TimetableController::mine()`, `lib/Controller/TimetableController.php:111`). Staff also have the cohort timetable (`CohortTimetableView.vue`, reading `objectsUrl('session')` at :60). `Session` has no authorization block, so OpenRegister applies no read rule of its own to it and, on a static reading, lets every signed-in user read every lesson; a visibility setting would be hollow without one. Under D10 the sessions move to planninq; the policy and the endpoint stay learniq's, and the reading goes through learniq's session reader.

## Data model

### `TimetableVisibilityPolicy` (new, slug `timetable-visibility-policy`, 0.1.0)

| property | type | default |
|---|---|---|
| `learnerSeesGroups` | `own`, `all` | `own` |
| `learnerSeesTeachers` | `none`, `related`, `all` | `related` |
| `learnerSeesRooms` | `none`, `related`, `all` | `related` |
| `instructorSeesGroups` | `own`, `all` | `all` |
| `instructorSeesTeachers` | `own`, `all` | `all` |
| `instructorSeesRooms` | `all` | `all` |
| `tenant_id` | string, required | |

`related` for a learner means the teachers and rooms of lessons in the learner's own timetable. Staff groups `team-leads` and `compliance-officers` always see all. One policy per tenant; when none exists the defaults apply. Authorization: read `authenticated`; create and update `team-leads`, `compliance-officers`.

### `Session` authorization (0.1.0 to 0.2.0)

- read: `instructors`, `team-leads`, `compliance-officers`, `hr` only. The register grammar cannot match "caller in the row's cohort's `learnerIds`" on a `Session` row, so learners get no direct row read and read lessons through the timetable endpoints. Before the block lands, every learner-facing read of `session` moves to an endpoint (task 1).
- create, update: unchanged groups (the staff groups that write sessions today, and the import handler's system writes).

## Endpoint

`GET /api/timetable/of?kind=cohort|teacher|room&id=&from=&to=`, `#[NoAdminRequired]`:

1. Resolve the caller's role (learner, instructor, or a staff group that sees all) with `DashboardRoleService`, the role source the menus use.
2. Apply the policy: for `own` and `related` compute the allowed ids from the caller's own timetable (the same `resolveCallerCohortIds()` the `mine` route uses).
3. Allowed: load the sessions of that cohort, teacher (`Cohort.teacherIds` and `substituteTeacherId`) or room (`roomId`) in the window through the session reader, project them with `TimetableProjector`, and return the same shape as `mine`. Not allowed: 403 with "Your school does not let you see this timetable".

The check is in the method body (gate 7). The session reader is the one seam that changes when `sessions-from-planninq` lands.

## Screens

- `Timetables` (`/timetables`, custom page, menu under Learning for everyone): a kind switch (group, teacher, room), a picker listing only the ids the caller may open (from `GET /api/timetable/of/options`), and the week view component `MyTimetable.vue` already uses, extracted to `TimetableWeek.vue`.
- `CohortTimetableView.vue` reads through `GET /api/timetable/of?kind=cohort`.
- Settings: a section "Timetable visibility" on the learniq settings page for `team-leads` and `compliance-officers`, editing the policy.

## Declarative versus imperative

| behaviour | path | reason |
|---|---|---|
| policy object and who may edit it | declarative | |
| Session object API read line | declarative authorization | |
| who may open which timetable | imperative, in the endpoint | the rule depends on the caller's own timetable (a relation across rows), which the register grammar cannot express |

## Seed data

VO example set: a policy with learners `own` groups, `related` teachers and `all` rooms, instructors `all`.

## Sibling note (planninq)

planninq's `timetableSession` is readable by every signed-in user by design (`school-timetable-target` design, reads "readable by any signed-in user by schema authorization"). Once learniq reads from planninq, a learner could read any timetable through planninq's `GET /api/timetable/sessions`. Planninq's lane should narrow that read to staff and system callers or to the identity filter's own rows; this change cannot do it from learniq.
