# Tasks: cohort-gradebook-batch-publish

## Implementation Tasks

### Task 1: Add the publish helpers
- **spec_ref**: `openspec/changes/cohort-gradebook-batch-publish/specs/grading/spec.md#requirement-a-teacher-previews-and-batch-publishes-a-cohorts-concept-grades`
- **files**: `src/utils/gradebookPublish.js`, `tests/unit-js/gradebookPublish.test.mjs`
- **acceptance_criteria**:
  - GIVEN marks 4.5, 6.0, 7.5, 8.0 and pass 5.5 WHEN summarised THEN 4 marks, average 6.5, lowest 4.5, highest 8.0, 3 pass
  - GIVEN 3 concept, 1 published, 1 valueless concept WHEN listed THEN exactly the 3 are publishable
  - GIVEN a refused entry WHEN reported THEN the rest count as published and the refusal names the learner and reason
- [x] Implement
- [x] Test

### Task 2: Add the publish panel to CohortGradebookView
- **spec_ref**: `openspec/changes/cohort-gradebook-batch-publish/specs/grading/spec.md#requirement-a-teacher-previews-and-batch-publishes-a-cohorts-concept-grades`
- **files**: `src/views/CohortGradebookView.vue`, `l10n/en.json`, `l10n/nl.json`
- **acceptance_criteria**:
  - GIVEN the gradebook WHEN a scope is picked THEN the distribution and histogram show for it
  - GIVEN "Publish N marks" WHEN confirmed THEN each concept entry gets the publish transition in turn
  - Every new string has an English and a Dutch catalogue value
- [x] Implement
- [x] Test

## Quality checklist

- `node --test tests/unit-js/gradebookPublish.test.mjs`, eslint, stylelint, prettier on touched files
- `openspec validate cohort-gradebook-batch-publish` passes
