# Tasks: lesson-ai-assist-actions

Feature tier: should (V1). Frontend only; no schema, so no seed data task and no migration.

## Implementation Tasks

### Task 1: Pure assist module and block helpers
- **spec_ref**: `openspec/changes/lesson-ai-assist-actions/specs/course-management/spec.md#requirement-assist-requests-carry-lesson-content-and-goal-titles-only`
- **files**: `src/utils/lessonAssist.js`, `src/utils/lessonBlocks.js`
- **acceptance_criteria**:
  - GIVEN any action WHEN its body is built THEN only the contract fields for that action are present, within the limits
  - GIVEN goals `{id, title}` WHEN the payload is built THEN titles go out and ids stay behind, in a fixed order
  - GIVEN a hermiq answer or an HTTP error WHEN classified THEN the outcome is one of ok, off, retry, busy, failed
  - GIVEN blocks with a draft WHEN serialised THEN the draft marker is absent
- [x] Implement
- [x] Test

### Task 2: Notice dialog and assist panel
- **spec_ref**: `openspec/changes/lesson-ai-assist-actions/specs/course-management/spec.md#requirement-the-lesson-composer-offers-four-ai-assist-actions-through-hermiq-only-when-hermiq-can-answer`
- **files**: `src/dialogs/LessonAssistNoticeDialog.vue`, `src/components/lesson/LessonAssistPanel.vue`
- **acceptance_criteria**:
  - GIVEN hermiq is not enabled WHEN the composer renders THEN the panel does not render
  - GIVEN the first call in a browser WHEN an action runs THEN the notice dialog asks for confirmation first
  - GIVEN an `off` outcome WHEN it arrives THEN the panel collapses to a note for the rest of the session
- [x] Implement
- [x] Test

### Task 3: Draft blocks, per-block rewrite, goal suggestions and the save guard in LessonComposer
- **spec_ref**: `openspec/changes/lesson-ai-assist-actions/specs/course-management/spec.md#requirement-every-assist-result-is-a-draft-the-teacher-keeps-or-discards`
- **files**: `src/views/LessonComposer.vue`
- **acceptance_criteria**:
  - GIVEN a draft WHEN it is inserted THEN it shows the AI draft label, the model service and Keep and Discard
  - GIVEN a pending draft WHEN the teacher saves THEN nothing is sent and the composer asks to keep or discard first
  - GIVEN an added goal suggestion WHEN the lesson is saved THEN `competencyIds` holds it and `lifecycle` is untouched
- [x] Implement
- [x] Test

### Task 4: Translations and user guide page
- **spec_ref**: `openspec/changes/lesson-ai-assist-actions/specs/course-management/spec.md#requirement-assist-requests-carry-lesson-content-and-goal-titles-only`
- **files**: `l10n/en.json`, `l10n/nl.json`, `l10n/*.js`, `docs/user-guide/user/`
- **acceptance_criteria**:
  - GIVEN every new string WHEN `npm run test:l10n` or the parity check runs THEN en and nl both hold it
  - GIVEN the guide WHEN a teacher reads it THEN it says the actions need hermiq, what goes to the model and that results are drafts
- [x] Implement
- [x] Test

## Verification
- [x] `openspec validate lesson-ai-assist-actions` passes
- [x] Diff-scoped checks green (eslint, stylelint, prettier on touched files; `npm run test:js-unit`)
- [ ] Before push: `composer check:strict`, `npm run lint`, `npm run format`, l10n checks and hydra gates run once, exit codes in the PR body

## Quality checklist
- PHPUnit: not applicable, no PHP changes.
- Newman: not applicable, no learniq endpoint.
- Browser: deferred until hermiq PR 962 is merged and the feature is enabled (test-plan TC-6).
- Screenshots: not possible without the live delegate; the guide page describes the flow in text.
- Dutch and English strings for every new label.
