# Tasks: ai-translated-catalogue-review

## Implementation Tasks

### Task 1: Seed the sidecar and teach the l10n build to ignore it
- **spec_ref**: `openspec/changes/ai-translated-catalogue-review/specs/ai-translated-catalogue/spec.md#requirement-the-seed-is-every-key-the-round-1-and-round-2-lanes-wrote`
- **files**: `l10n/ai-translated.json`, `scripts/build-l10n-js.js`
- **acceptance_criteria**:
  - GIVEN the derivation in design D5 WHEN the sidecar is written THEN it lists the 1006 keys plus this change's own Dutch keys, sorted
  - GIVEN the sidecar WHEN `npm run check:l10n-js` runs THEN it exits 0 and writes no `ai-translated.js`
- [x] Implement
- [x] Test

### Task 2: The shape test
- **spec_ref**: `openspec/changes/ai-translated-catalogue-review/specs/ai-translated-catalogue/spec.md#requirement-the-sidecar-lists-the-unreviewed-ai-written-catalogue-keys`
- **files**: `tests/unit-js/aiTranslatedCatalogue.test.mjs`
- **acceptance_criteria**:
  - GIVEN the sidecar WHEN the test runs THEN language, sortedness, uniqueness, membership and allowed members are asserted
- [x] Implement
- [x] Test

### Task 3: Service and admin endpoints
- **spec_ref**: `openspec/changes/ai-translated-catalogue-review/specs/ai-translated-catalogue/spec.md#requirement-marking-a-key-reviewed-removes-it-from-the-sidecar`
- **files**: `lib/Service/AiTranslatedCatalogue.php`, `lib/Controller/AiTranslationReviewController.php`, `appinfo/routes.php`, tests
- **acceptance_criteria**:
  - GIVEN an admin WHEN GET THEN items carry key, source, value
  - GIVEN a listed key WHEN marked THEN it leaves the file, the rest unchanged; unknown key 404; read-only 409
- [x] Implement
- [x] Test

### Task 4: The admin settings section
- **spec_ref**: `openspec/changes/ai-translated-catalogue-review/specs/ai-translated-catalogue/spec.md#requirement-the-admin-settings-list-the-keys-with-source-and-dutch-value`
- **files**: `src/views/settings/AiTranslationReviewSection.vue`, `src/views/settings/AdminRoot.vue`, `l10n/en.json`, `l10n/nl.json`
- **acceptance_criteria**:
  - GIVEN the admin page WHEN opened THEN the list shows with a filter, 50 rows a page and a Reviewed button per row
- [x] Implement
- [x] Test

### Task 5: Document the convention
- **spec_ref**: `openspec/changes/ai-translated-catalogue-review/specs/ai-translated-catalogue/spec.md#requirement-the-sidecar-lists-the-unreviewed-ai-written-catalogue-keys`
- **files**: `docs/Technical/ai-translated-catalogue.md`
- **acceptance_criteria**:
  - GIVEN another app's maintainer WHEN they read the page THEN they can add the sidecar, the filter, the test and the review surface
- [x] Implement
- [x] Test

## Quality checklist

- PHPUnit for the service and controller; node test for the sidecar
- en and nl strings for the section, listed in the sidecar themselves
- `npm run check:l10n-js` and `npm run check:schema-l10n` pass
- `openspec validate ai-translated-catalogue-review` passes
