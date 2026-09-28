# Test Plan: sessions-from-planninq

## Test Cases

### TC-1: Resolver picks the source
- **spec_ref**: `openspec/changes/sessions-from-planninq/specs/timetable-source/spec.md#requirement-a-resolver-picks-planninq-when-it-is-installed-req-001`
- **type**: regression
- **preconditions**: app manager and app config doubles
- **steps**: resolve with planninq installed, absent, and forced to learniq
- **expected result**: planninq, local, local
- **test command**: `vendor/bin/phpunit --filter TimetableSourceResolverTest`

### TC-2: Planninq source maps and fails loudly
- **spec_ref**: `openspec/changes/sessions-from-planninq/specs/timetable-source/spec.md#requirement-the-planninq-source-reads-through-planninqs-query-event-req-002`
- **type**: api
- **preconditions**: a verbatim copy of planninq's query event; a dispatcher that answers, errors or stays silent
- **steps**: read a cohort and a teacher
- **expected result**: learniq-shaped sessions with `source: planninq`; a raised error when unanswered or refused
- **test command**: `vendor/bin/phpunit --filter PlanninqTimetableSourceTest`

### TC-3: Planninq-target import writes no Session
- **spec_ref**: `openspec/changes/sessions-from-planninq/specs/timetable-source/spec.md#requirement-a-timetable-import-job-delivers-into-planninq-when-planninq-is-the-source-req-003`
- **type**: regression
- **preconditions**: resolver on planninq, a verbatim copy of integriq's event, a dispatcher that answers
- **steps**: transition a Zermelo `timetable-import` job to running
- **expected result**: integriq asked for `roster-zermelo`; no `session` save; counts on the job; `partial`
- **test command**: `vendor/bin/phpunit --filter 'TimetableImportHandlerTest|PlanninqTimetableImportTest'`

### TC-4: Integriq absent fails the job
- **spec_ref**: `openspec/changes/sessions-from-planninq/specs/timetable-source/spec.md#requirement-a-timetable-import-job-delivers-into-planninq-when-planninq-is-the-source-req-003`
- **type**: regression
- **preconditions**: resolver on planninq, integriq event class name pointing nowhere
- **steps**: run the job
- **expected result**: job fails with a message naming integriq; no `session` save
- **test command**: `vendor/bin/phpunit --filter PlanninqTimetableImportTest`

### TC-5: Conflicts on planninq lessons
- **spec_ref**: `openspec/changes/sessions-from-planninq/specs/timetable-source/spec.md#requirement-conflict-detection-runs-on-the-adapters-lessons-req-004`
- **type**: regression
- **preconditions**: two overlapping planninq lessons with the same `teacherUserId`
- **steps**: `scanWindow()`
- **expected result**: one `teacher-double-booking` conflict saved for both ids
- **test command**: `vendor/bin/phpunit --filter TimetableConflictDetectorTest`

### TC-6: Cohort and personal timetables read through the source
- **spec_ref**: `openspec/changes/sessions-from-planninq/specs/timetable-source/spec.md#requirement-both-timetable-pages-read-through-the-adapter-req-005`
- **type**: security
- **preconditions**: controller with a resolver double
- **steps**: call `cohort()` for a readable and an unreadable cohort; call `mine()` with planninq
- **expected result**: 200 with the source's lessons; 403 with no source call; teacher lessons merged without duplicates
- **test command**: `vendor/bin/phpunit --filter TimetableControllerTest`

## Coverage Summary
REQ-001 TC-1; REQ-002 TC-2; REQ-003 TC-3, TC-4; REQ-004 TC-5; REQ-005 TC-6.

## Out of Scope
A browser run of both pages against an instance with planninq: the lane has no instance of its own and must not deploy to the shared one. The pages' requests are covered by the controller tests; the view changes are small and linted.
