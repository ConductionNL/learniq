---
kind: config
---

# Proposal: subject-and-teacher-assignment

## Summary
`Cohort.teacherIds` is a flat array of Nextcloud user IDs with no per-subject or per-teacher structure (round-1 findings 1.8, 1.7, 2.14, all NICE tier; corpus `../parnassys/round1/documented-column.md` rows 1.7/1.8/2.14, `../gibbon/round1/pages/FormGroup.md`, `../po-las/round1/documented-columns.md` row 1.7 ESIS "Duobaan met"). This change adds a `SubjectTeacherAssignment` join distinct from `Cohort.teacherIds` (class x subject, for VO/MBO/HE where a class has a different teacher per subject), an additive `Cohort.teacherAssignments` property carrying a duo-partner role and working days per teacher (for PO, where two teachers may share one groep), and a new `Staff` index+detail under People (roles, qualifications, working days) since the nearest existing schema, `TeacherAvailability`, only covers conference-round slots.

## Motivation
Gibbon's Form Group page names "tutor(s)" plural with a duo-partner distinct from a single main teacher (`FormGroup.md`); ESIS's `Duobaan met` field names the duo partner explicitly (`po-las/round1/documented-columns.md` row 1.7); ParnasSys links staff to a group with a `Leerkrachtrooster` naming which teacher covers which dagdelen. None of this is expressible on `Cohort.teacherIds`, which is a bare array of strings with no role or day attached to any entry. Separately, VO/MBO/HE needs a class x subject assignment (row 1.8: "n/a" in every corpus source for `parnassys`/`po-las` since those are PO-only systems, but this is a real gap in learniq for its VO/MBO/HE segments) that is structurally different from "who teaches this cohort" — a VO class has one teacher per subject, not one teacher for the whole cohort. Finally, `Staff` (row 2.14) has no schema at all; the closest existing one, `TeacherAvailability`, exists only to feed `ConferenceScheduleGenerator` and carries no roles/qualifications.

## Affected Projects
- [x] Project: `learniq` — `lib/Settings/learniq_register.json` gains `Staff` and `SubjectTeacherAssignment` schemas plus an additive `Cohort.teacherAssignments`; `src/manifest.d/people.json` gains a Staff index+detail pair; `src/manifest.d/learning.json` gains a SubjectTeacherAssignments index+detail pair and a roster widget on `CohortDetail`.

## Scope

### In Scope
- `SubjectTeacherAssignment` schema: `cohortId` ($ref Cohort), `courseId` ($ref Course, standing in for "subject"), `teacherId` (Nextcloud user ID). Index + detail pages, plus an object-list widget on `CohortDetail` filtered by `cohortId`.
- `Cohort.teacherAssignments` — additive array of `{ teacherId, role: primary | duo-partner, days: [] }`, alongside the existing `teacherIds` (untouched, for back-compat with any consumer reading the flat list).
- `Staff` schema: `ncUserId`, `roles` (array, enum), `qualifications` (array of free text), `workingDays` (array, weekday enum). Index + detail pages under People, matching the Enrolment/Credential/School convention.
- Register-JSON unit tests for both new schemas and the `Cohort.teacherAssignments` addition.

### Out of Scope
- Deriving `SubjectTeacherAssignment` or `teacherAssignments` from an external rostering import (that is `data-mapping-profile-presets`/`integriq-adapter-rostering-imports`, a different change).
- Removing or deprecating `Cohort.teacherIds` — kept unchanged; `teacherAssignments` is additive.
- A `TeacherAvailability`-style structured per-slot calendar on `Staff.workingDays` — a plain weekday-enum array, not a calendar.

## Approach
Two new plain resource-metadata schemas (no lifecycle, same shape as `Room`), one additive array property on `Cohort`, and manifest pages/widgets following the existing index+detail and roster-widget conventions. Declarative only (ADR-031).

## New Dependencies
None.

## Impact
- `lib/Settings/learniq_register.json`: two new schemas (`Staff`, `SubjectTeacherAssignment`), one additive property (`Cohort.teacherAssignments`), register version bump.
- `src/manifest.d/people.json`: one new menu entry, two new pages (Staff index+detail).
- `src/manifest.d/learning.json`: two new pages (SubjectTeacherAssignments index+detail) and one new widget on `CohortDetail`.
- `tests/Unit/Settings/SubjectAndTeacherAssignmentRegisterTest.php`: new.

## Cross-Project Dependencies
None.

## Rollback Strategy
Revert the register-JSON and manifest-JSON diffs; all additive/declarative, no migration to unwind.

## Open Questions
None.
