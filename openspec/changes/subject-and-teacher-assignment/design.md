# Design: subject-and-teacher-assignment

## Context
`Cohort.teacherIds` is a flat `string[]` (Nextcloud user IDs). Two distinct gaps sit behind that one field: PO needs a duo-partner role and working days per teacher on the SAME cohort (row 1.7); VO/MBO/HE needs a DIFFERENT teacher per subject within the same class (row 1.8). Neither is expressible on a flat array of strings. `Staff` (row 2.14) has no schema; `TeacherAvailability` exists but is scoped to one `ConferenceRound`'s free-time blocks, not a general staff record.

## Goals / Non-Goals
- **Goal**: express duo-partner + days without touching the existing `teacherIds` shape.
- **Goal**: express a class x subject teacher assignment as its own record, not a Cohort property (a VO class can have many subject teachers; cramming that onto `Cohort` would mean an unbounded, ever-growing property instead of queryable objects).
- **Goal**: a minimal general Staff record.
- **Non-goal**: rostering-import automation (a later change, per `data-mapping-profile-presets`).
- **Non-goal**: a structured per-slot staff calendar (that is `TeacherAvailability`'s job, scoped to conference rounds; `Staff.workingDays` is a plain weekly pattern).

## Decisions

### Decision 1: `SubjectTeacherAssignment` is its own schema, not a Cohort array
A VO class's subject-teacher list can be a dozen entries (one per subject) and grows as courses are added; keeping it as queryable OpenRegister objects (filterable by `cohortId` or `courseId`) matches every other join-shaped record in this register (e.g. `Enrolment` itself is a learner x course join, not an array on either side). An array-of-objects property on `Cohort` was rejected for the same reason `Enrolment` isn't an array on `LearnerProfile`.

### Decision 2: `Cohort.teacherAssignments` is additive, `teacherIds` is untouched
Rewriting `teacherIds` into an array of objects would be a breaking shape change for any existing consumer reading it as `string[]` (the manifest's generic Data widget renders whatever is in `properties`, so a shape change there is visible immediately, unlike a purely additive property). `teacherAssignments` is new and optional; a Cohort with no duo-partner still only needs `teacherIds`.

### Decision 3: schema slugs chosen to avoid the D07 `$ref` bug from the start
Learned from this lane's own `school-and-location-records` change (Vestiging rename, gate-106 + the camelCase-to-kebab `$ref` resolution bug): both new schemas here use a slug that is the plain lowercase concatenation of the PascalCase name with no separators (`staff`, `subjectteacherassignment`), confirmed free in `contracts/fleet-schema-slugs.json` before writing any JSON. This sidesteps both the cross-app collision check and the `slugify-ref-relation-resolver` defect class in one move, without needing a Dutch-word rename this time.

## Declarative-vs-imperative decision (ADR-031)
No lifecycle, aggregation, calculation, or notification behaviour. Two new flat schemas, one additive array property, and manifest pages/widgets — JSON only.

## Seed Data (ADR-001)
- `Staff` seeds: two, one `roles: ["teacher"]` with `workingDays` Monday-Friday, one `roles: ["teacher", "mentor"]` with `workingDays` Monday/Wednesday/Friday (the duo-partner half of the seed below).
- This change adds its own `Cohort` seed ("Groep 5/6") since this branch is cut from `origin/development`, independent of this lane's stacked `school-and-location-records`/`enrolment-statutory-fields` changes, which are not merged yet and carry their own separate "Groep 5/6" seed on their own branch. `teacherAssignments` on this seed carries a `primary` entry Monday-Wednesday and a `duo-partner` entry Thursday-Friday, referencing the two `Staff` seeds' `ncUserId`s.
- `SubjectTeacherAssignment` seeds: two, both referencing this change's own "Groep 5/6" Cohort seed, one per (fabricated) subject `Course` id, each with a distinct `teacherId`.

## Risks / Trade-offs
[Risk] Two overlapping "who teaches this cohort" surfaces (`teacherIds`, `teacherAssignments`, `SubjectTeacherAssignment`) could confuse an integrator → Mitigation: each is documented with its own scope in the schema description (general list, duo-partner/day detail, per-subject assignment); no code path merges them, so there is nothing to keep in sync incorrectly.

## Migration Plan
Declarative only. Revert the JSON diffs to roll back.

## Open Questions
None.
