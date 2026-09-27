# Tasks: assignment-missing-submissions-view

## Implementation Tasks

### Task 1: Add the hand-in status helpers
- **spec_ref**: `openspec/changes/assignment-missing-submissions-view/specs/assignments/spec.md#requirement-a-teacher-sees-who-has-not-handed-in-an-assignment`
- **files**: `src/utils/handInStatus.js`, `tests/unit-js/handInStatus.test.mjs`
- **acceptance_criteria**:
  - GIVEN 24 learners, 18 handed in, 2 drafts, 4 without WHEN computed THEN the summary is 18 of 24 with 2 started and 4 not started
  - GIVEN no cohortId WHEN the roster is built THEN it is the de-duplicated union of the course's cohorts
  - GIVEN a passed due date WHEN computed THEN missing learners are overdue
  - GIVEN only the student view WHEN checked THEN the section is hidden
- [x] Implement
- [x] Test

### Task 2: Add the AssignmentHandInStatus section and wire it
- **spec_ref**: `openspec/changes/assignment-missing-submissions-view/specs/assignments/spec.md#requirement-a-teacher-sees-who-has-not-handed-in-an-assignment`
- **files**: `src/components/sections/AssignmentHandInStatus.vue`, `src/registry.js`, `src/manifest.d/learning.json`, `tests/unit-js/registryComponentCoverage.test.mjs`, `l10n/en.json`, `l10n/nl.json`
- **acceptance_criteria**:
  - GIVEN the manifest WHEN AssignmentDetail renders THEN a body section named AssignmentHandInStatus receives the assignment id
  - GIVEN the registry WHEN checked THEN every bodyWidgets component is registered
  - Every new string has an English and a Dutch catalogue value
- [x] Implement
- [x] Test

## Quality checklist

- `npm run test:js-unit`, `npm run check:manifest`, `npm run lint`, `npm run format`
- No em-dashes, sentence case in every new label
- `openspec validate assignment-missing-submissions-view` passes
