# Design: timetabling-standby-slots

## Context

Substitution exists and is guarded: `Session` transitions `substitute-teacher` and `substitute-teacher-in-progress` require `SessionChangeGuard` (`lib/Settings/learniq_register.json:6331-6343`, `lib/Lifecycle/SessionChangeGuard.php`). The dialog that feeds them asks for a raw user id (`src/dialogs/SubstitutionModal.vue:72-80`). Standby planning gives the dialog a list to choose from.

## Sessions after D10

Candidates are computed against lessons read through the timetable's session reader. After `sessions-from-planninq`, a teacher's lessons are found by `teacherUserId` on planninq's `timetableSession` rows; before it, by `Cohort.teacherIds` and `substituteTeacherId`. `StandbySlot` is a learniq object and does not reference sessions.

## Data model

### `StandbySlot` (new, slug `standby-slot`, 0.1.0)

| property | type | notes |
|---|---|---|
| `teacherId` | string, required | Nextcloud user id, a `Staff.ncUserId` |
| `weekday` | `monday` to `friday`, nullable | weekly slot |
| `date` | date, nullable | a one-off slot; one of `weekday` or `date` is required |
| `startsAt`, `endsAt` | time `HH:MM`, required | |
| `vestigingId` | uuid, `$ref: Vestiging`, nullable | |
| `validFrom`, `validUntil` | date, required | usually the school year |
| `tenant_id` | string, required | |

Authorization: read `authenticated` (a teacher sees their own and colleagues' standby, as on a staff room board); create and update `team-leads`, `compliance-officers`.

## Candidates

`SubstitutionCandidateService::forSession(string $sessionId): array`, route `GET /api/substitution/candidates?sessionId=` (`#[NoAdminRequired]`, allowed for the callers `SessionChangeGuard` allows: teachers of the cohort and members of `admin` or `coordinators`, check in the body, gate 7):

1. Standby: slots valid on the lesson's date, matching its weekday or date and overlapping its time, at the same location when both have one. Each listed as `{userId, displayName, reason: "on standby 10:15 to 11:05"}`.
2. Free: staff whose `workingDays` include the weekday and who have no lesson overlapping the time. Reason "free then".
3. A standby teacher who does have a lesson then goes last with "has a lesson then".

The list never includes the absent teacher (the cohort's own teachers for this lesson).

## Screens

- `SubstitutionModal.vue`: the free-text field becomes an `NcSelect` with `inputLabel` (gate: NcSelect labels) grouped "On standby" and "Free", each option with its reason, and a search over all staff below. The chosen value is still written to `substituteTeacherId` and still passes `SessionChangeGuard`.
- `StandbyPlanning` (`/standby`, custom page under Learning for staff groups): weekdays by lesson hours, teachers in each cell, add and remove.
- `MyTimetable.vue`: the teacher's standby slots in the viewed week, drawn as standby blocks (from `GET /api/timetable/mine`, which adds `standby: [...]` for staff callers).

## Declarative versus imperative

| behaviour | path | reason |
|---|---|---|
| standby slots | declarative schema | |
| candidate list | imperative, `SubstitutionCandidateService` | a read across slots, staff and lessons at one time; stores nothing and assigns nothing |

## Seed data

VO example set: twelve standby slots for the week (two teachers per morning hour 1 to 3, one per afternoon hour), for the school year, at the main location; one teacher on standby who also teaches on Wednesday hour 2, to show the "has a lesson then" case.
