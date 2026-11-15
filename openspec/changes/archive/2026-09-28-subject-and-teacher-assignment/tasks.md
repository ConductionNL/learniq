# Tasks: subject-and-teacher-assignment

## Implementation Tasks

### Task 1: Add the Staff and SubjectTeacherAssignment schemas
- **spec_ref**: `openspec/changes/subject-and-teacher-assignment/specs/school-structure/spec.md#requirement-staff-is-persisted-as-an-openregister-record-with-roles-qualifications-and-working-days`, `#requirement-a-class-x-subject-teacher-join-exists-distinct-from-cohortteacherids`
- **files**: `lib/Settings/learniq_register.json` (new `Staff`, `SubjectTeacherAssignment` schemas; register version bump; seed data per design.md)
- **acceptance_criteria**:
  - GIVEN `Staff` WHEN read THEN it declares `ncUserId`, `roles` (array enum), `qualifications` (array), `workingDays` (array enum), no lifecycle
  - GIVEN `SubjectTeacherAssignment` WHEN read THEN it declares `cohortId` ($ref Cohort), `courseId` ($ref Course), `teacherId`, no lifecycle
  - GIVEN both schemas' `slug` WHEN read THEN they are `staff` and `subjectteacherassignment` respectively (confirmed free in `contracts/fleet-schema-slugs.json`)
- [x] Implement
- [x] Test

### Task 2: Add Cohort.teacherAssignments
- **spec_ref**: `openspec/changes/subject-and-teacher-assignment/specs/school-structure/spec.md#requirement-cohort-declares-a-duo-partner-role-and-working-days-per-teacher`
- **files**: `lib/Settings/learniq_register.json` (`Cohort.properties.teacherAssignments`, additive array; this change adds its own "Groep 5/6" Cohort seed, since this branch does not carry this lane's stacked school-and-location-records/enrolment-statutory-fields seeds)
- **acceptance_criteria**:
  - GIVEN `Cohort` WHEN read THEN it declares `teacherAssignments` (array of `{teacherId, role, days}`, default `[]`) and `teacherIds`/`required` are unchanged
  - GIVEN this change's "Groep 5/6" Cohort seed WHEN read THEN `teacherAssignments` has one `primary` and one `duo-partner` entry with non-overlapping `days`
- [x] Implement
- [x] Test

### Task 3: Staff and SubjectTeacherAssignment pages, plus a CohortDetail roster widget
- **spec_ref**: `openspec/changes/subject-and-teacher-assignment/specs/school-structure/spec.md#requirement-frontend-is-declarative-for-staff-and-subjectteacherassignment`
- **files**: `src/manifest.d/people.json` (Staff menu entry + index+detail); `src/manifest.d/learning.json` (SubjectTeacherAssignments index+detail; a `coh-subject-teachers` object-list widget on `CohortDetail` filtered by `cohortId`)
- **acceptance_criteria**:
  - GIVEN the manifest WHEN built THEN `Staff` appears as an index page under People with a working detail route
  - GIVEN `CohortDetail` WHEN rendered THEN it shows a subject-teacher roster widget listing `SubjectTeacherAssignment`s filtered to this cohort
  - GIVEN `npm run check:manifest` WHEN run THEN it passes
- [x] Implement
- [x] Test

### Task 4: Register-JSON unit tests
- **spec_ref**: all requirements in `specs/school-structure/spec.md` above
- **files**: `tests/Unit/Settings/SubjectAndTeacherAssignmentRegisterTest.php` (new)
- **acceptance_criteria**:
  - GIVEN the test suite WHEN run THEN it asserts `Staff`/`SubjectTeacherAssignment` shape, `Cohort.teacherAssignments`'s additive shape, and the seed fixtures (duo-partner day split, two distinct subject-teacher assignments)
- [x] Implement
- [x] Test

## Verification
- [x] All tasks checked off
- [x] `openspec validate --changes subject-and-teacher-assignment --strict` passes
- [x] `npm run check:manifest`, `npm run check:register`, `npm run check:json-strict` pass
- [x] `vendor/bin/phpunit --filter SubjectAndTeacherAssignmentRegisterTest` passes

## Tests (company-wide ADR-009)
- `tests/Unit/Settings/SubjectAndTeacherAssignmentRegisterTest.php` covers schema shape and seed fixtures.
- N/A — no new or changed API endpoints.
- N/A — no custom Vue component; manifest-declarative pages/widget only.

## Documentation (company-wide ADR-010)
- N/A — no user-facing feature doc beyond the page/property labels.

## i18n (company-wide ADR-005)
- New schema/property/menu labels get en/nl catalogue entries per ADR-007/025.

## Compliance
- `openspec validate --change subject-and-teacher-assignment --strict` passes before this change is marked ready for apply.
- Diff is confined to `lib/Settings/learniq_register.json`, `src/manifest.d/people.json`, `src/manifest.d/learning.json`, `l10n/*.json`, and the new test file (ADR-031, no PHP/Vue behaviour code).
