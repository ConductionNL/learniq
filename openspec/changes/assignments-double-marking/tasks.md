# Tasks: assignments-double-marking

## Implementation tasks

### Task 1: Register: markers on Assignment, SubmissionMark schema, Submission fields
- **spec_ref**: `specs/assignments/spec.md#requirement-an-assignment-can-ask-for-more-than-one-marker`, `#requirement-each-marker-scores-in-their-own-submissionmark`
- **files**: `lib/Settings/learniq_register.json` (Assignment 0.5.0, Submission 0.5.0, new SubmissionMark 0.1.0 with lifecycle, authorization and aggregations; `info.version` minor bump)
- **acceptance_criteria**:
  - GIVEN the register WHEN validated THEN `markersPerSubmission` is 1 to 5 with default 1 and `finalGradeRule` is `manual`, `average` or `highest`
  - GIVEN `SubmissionMark` WHEN read THEN it has the properties, lifecycle and authorization of design.md
- [ ] Implement
- [ ] Test: `tests/Unit/Settings/DoubleMarkingRegisterTest.php` pins the shapes and the authorization block; `npm run check:register` and `npm run check:json-strict` pass

### Task 2: Allocation service and route
- **spec_ref**: `specs/assignments/spec.md#requirement-the-teacher-in-charge-allocates-markers`
- **files**: `lib/Service/SubmissionMarkAllocationService.php`, `lib/Controller/SubmissionMarkController.php`, `appinfo/routes.php`
- **acceptance_criteria**:
  - GIVEN a second call with the same markers WHEN allocate runs THEN no duplicate `SubmissionMark` is created
  - GIVEN a marker who is one of the submission's learners WHEN allocate runs THEN that marker is refused with a reason
- [ ] Implement
- [ ] Test: `tests/Unit/Service/SubmissionMarkAllocationServiceTest.php`; hydra gates 5, 7 and 30 (route auth, IDOR, route reachability) pass

### Task 3: Read other markers' marks
- **spec_ref**: `specs/assignments/spec.md#requirement-a-marker-sees-other-marks-only-after-submitting-their-own`
- **files**: `lib/Controller/SubmissionMarkController.php` (`marks()`), `appinfo/routes.php`
- **acceptance_criteria**:
  - GIVEN a marker whose own mark is `draft` WHEN they call the route THEN only their own mark comes back
- [ ] Implement
- [ ] Test: `tests/Unit/Controller/SubmissionMarkControllerTest.php` covers draft, submitted and team-lead callers

### Task 4: Allocate markers modal and submissions list action
- **spec_ref**: `specs/assignments/spec.md#requirement-the-teacher-in-charge-allocates-markers`
- **files**: `src/modals/AllocateMarkersModal.vue`, `src/manifest.d/learning.json`, `src/registry.js`
- **acceptance_criteria**:
  - GIVEN an assignment with `markersPerSubmission` 1 WHEN the submissions list opens THEN the action is not shown
- [ ] Implement
- [ ] Test: Playwright e2e `tests/e2e/double-marking.spec.ts` (allocate two markers)

### Task 5: Marking and final grade in MarkSubmissionView
- **spec_ref**: `specs/assignments/spec.md#requirement-each-marker-scores-in-their-own-submissionmark`, `#requirement-one-person-sets-the-final-grade-once-every-mark-is-in`
- **files**: `src/views/MarkSubmissionView.vue`
- **acceptance_criteria**:
  - GIVEN double marking WHEN a marker saves THEN their `SubmissionMark` is submitted and the Submission is neither changed nor returned
  - GIVEN every mark submitted WHEN the teacher in charge saves the final grade THEN `saveAndReturn()` runs as today and `finalGradeSetBy` and `finalGradeRuleApplied` are written
- [ ] Implement
- [ ] Test: `tests/unit-js` for the mode switch and the prefill rule; e2e `tests/e2e/double-marking.spec.ts` (two markers, agreed grade)

### Task 6: Seed data and translations
- **files**: `lib/Settings/learniq_mock_register.json`, `l10n/en.json`, `l10n/nl.json`, `l10n/*.js`
- **acceptance_criteria**:
  - GIVEN the gate-101 checker WHEN run THEN the seed rows of design.md validate
  - GIVEN `npm run check:schema-l10n` WHEN run THEN the uncovered count does not grow
- [ ] Implement
- [ ] Test: gate 101, `npm run check:schema-l10n`, `npm run check:l10n-js`

## Verification
- `openspec validate assignments-double-marking --strict` passes
- `composer check:strict` and `npm run lint` show no new finding against the development baseline
