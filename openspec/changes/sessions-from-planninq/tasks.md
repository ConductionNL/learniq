# Tasks: sessions-from-planninq

## Implementation Tasks

### Task 1: Timetable source adapter and resolver (V1)
- **spec_ref**: `openspec/changes/sessions-from-planninq/specs/timetable-source/spec.md#requirement-a-resolver-picks-planninq-when-it-is-installed-req-001`
- **files**: `lib/Timetabling/Source/*.php`, `tests/Stubs/Planninq/Event/TimetableSessionsQueryEvent.php`, `tests/Unit/Timetabling/Source/*Test.php`
- **acceptance_criteria**:
  - GIVEN planninq installed WHEN resolved THEN the planninq source answers; otherwise the local source
  - GIVEN planninq silent WHEN read THEN the source raises instead of returning nothing
- [x] Implement
- [x] Test

### Task 2: Planninq branch of the timetable-import job (V1)
- **spec_ref**: `openspec/changes/sessions-from-planninq/specs/timetable-source/spec.md#requirement-a-timetable-import-job-delivers-into-planninq-when-planninq-is-the-source-req-003`
- **files**: `lib/Timetabling/PlanninqTimetableImport.php`, `lib/Timetabling/TimetableImportHandler.php`, `lib/Timetabling/TimetableConnectorClient.php`, `tests/Stubs/Integriq/Event/RosterImportRequestedEvent.php`, tests
- **acceptance_criteria**:
  - GIVEN planninq as source WHEN the job runs THEN integriq is asked, counts land on the job and no Session is written
  - GIVEN integriq absent WHEN the job runs THEN it fails with a readable message
- [x] Implement
- [x] Test

### Task 3: Conflict detection on the adapter's lessons (V1)
- **spec_ref**: `openspec/changes/sessions-from-planninq/specs/timetable-source/spec.md#requirement-conflict-detection-runs-on-the-adapters-lessons-req-004`
- **files**: `lib/Timetabling/TimetableConflictDetector.php`, `lib/Timetabling/SessionOverlapEvaluator.php`, tests
- **acceptance_criteria**:
  - GIVEN two overlapping planninq lessons for one teacher WHEN scanned THEN a teacher double booking is queued
- [x] Implement
- [x] Test

### Task 4: Cohort and personal timetable read through the adapter (V1)
- **spec_ref**: `openspec/changes/sessions-from-planninq/specs/timetable-source/spec.md#requirement-both-timetable-pages-read-through-the-adapter-req-005`
- **files**: `lib/Controller/TimetableController.php`, `lib/Service/TimetableProjector.php`, `appinfo/routes.php`, `src/api/timetable.js`, `src/views/CohortTimetableView.vue`, `src/views/MyTimetable.vue`, `l10n`, tests
- **acceptance_criteria**:
  - GIVEN a readable cohort WHEN its timetable loads THEN the current source's lessons show
  - GIVEN an unreadable cohort WHEN requested THEN 403
  - GIVEN a planninq lesson WHEN shown THEN it does not open as a learniq session and has no Manage button
- [x] Implement
- [x] Test

## Verification
- [x] All tasks checked off
- [x] `openspec validate sessions-from-planninq` passes

## Quality checklist

- New services covered by PHPUnit tests.
- The new endpoint covered by controller tests; no instance for Newman.
- Dutch catalogue value for every new string; `npm run l10n:build`.
