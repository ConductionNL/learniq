# Tasks: differentiation-not-styles-copy

## Implementation Tasks

### Task 1: Rewrite the four forms' copy
- **spec_ref**: `openspec/changes/differentiation-not-styles-copy/specs/learning-plan/spec.md#requirement-differentiation-forms-speak-of-support-needs-level-and-goal`
- **files**: `lib/Settings/learniq_register.json` (titles, property descriptions, `x-notes`, versions), `l10n/en.json`, `l10n/nl.json`, `l10n/en.js`, `l10n/nl.js`
- **acceptance_criteria**:
  - GIVEN each rewritten property WHEN read THEN its description is plain and its former rationale is in `x-notes`
  - GIVEN every new title and description WHEN looked up THEN it has an English key and a Dutch value
- [x] Implement
- [x] Test

### Task 2: Wording guard and settings note
- **spec_ref**: `openspec/changes/differentiation-not-styles-copy/specs/learning-plan/spec.md#requirement-style-matching-wording-never-reaches-a-product-surface`, `#requirement-settings-explain-the-evidence-once`
- **files**: `tests/Unit/Settings/SupportNeedsVocabularyTest.php` (new), `src/views/LearniqSettings.vue`
- **acceptance_criteria**:
  - GIVEN the product surfaces WHEN scanned THEN no hit, and a planted hit in a temp file is caught by the same matcher
  - GIVEN Settings WHEN the AI section renders THEN the note is shown
- [x] Implement
- [x] Test

## Quality checklist
- Register test for the copy and the scan.
- No endpoint or behaviour change.
- Dutch and English strings for every new label.
- `openspec validate differentiation-not-styles-copy` passes.
