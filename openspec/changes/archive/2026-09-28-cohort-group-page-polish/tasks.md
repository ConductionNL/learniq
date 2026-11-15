# Tasks: cohort-group-page-polish

## Implementation Tasks

### Task 1: Add Cohort.notes
- **spec_ref**: `openspec/changes/cohort-group-page-polish/specs/school-structure/spec.md#requirement-cohort-carries-free-text-notes`
- **files**: `lib/Settings/learniq_register.json` (`Cohort.properties.notes`, additive nullable string; register version bump; a "Groep 7" Cohort seed with `notes` set)
- **acceptance_criteria**:
  - GIVEN `Cohort` WHEN read THEN it declares `notes` (nullable string, default null), and `required` is unchanged
  - GIVEN the seed data WHEN read THEN one Cohort seed carries a non-null `notes` value
- [x] Implement
- [x] Test

### Task 2: Add the notes and today's-sessions widgets to CohortDetail
- **spec_ref**: `openspec/changes/cohort-group-page-polish/specs/school-structure/spec.md#requirement-cohortdetail-surfaces-notes-and-a-today-scoped-session-view`
- **files**: `src/manifest.d/learning.json` (`CohortDetail.config.widgets`: a `coh-notes` Data widget scoped via `content.include: ["notes"]`; a `coh-sessions-today` object-list widget filtered by `cohortId: "@objectId"` and `startsAt: {gte: "@today", lt: "@today+1d"}`; layout entries for both)
- **acceptance_criteria**:
  - GIVEN `CohortDetail` WHEN read THEN its `widgets` array includes `coh-notes` (type `data`, `content.include` containing exactly `notes`) and `coh-sessions-today` (type `object-list`, `content.filter.startsAt` using the `@today`/`@today+1d` tokens)
  - GIVEN `npm run check:manifest` WHEN run THEN it passes
- [x] Implement
- [x] Test

### Task 3: Register-JSON unit tests
- **spec_ref**: all requirements in `specs/school-structure/spec.md` above
- **files**: `tests/Unit/Settings/CohortGroupPagePolishRegisterTest.php` (new)
- **acceptance_criteria**:
  - GIVEN the test suite WHEN run THEN it asserts `Cohort.notes`'s shape and the seed fixture
- [x] Implement
- [x] Test

## Verification
- [x] All tasks checked off
- [x] `openspec validate --changes cohort-group-page-polish --strict` passes
- [x] `npm run check:manifest`, `npm run check:register`, `npm run check:json-strict` pass
- [x] `vendor/bin/phpunit --filter CohortGroupPagePolishRegisterTest` passes
- [x] `python3 vendor/conduction/hydra-gates/hydra-gates/scripts/lib/check_schema_property_meta.py lib/Settings/learniq_register.json` and `check_manifest_l10n_coverage.py .` both clean before the first push (lesson from `subject-and-teacher-assignment`)

## Tests (company-wide ADR-009)
- `tests/Unit/Settings/CohortGroupPagePolishRegisterTest.php` covers `Cohort.notes` shape and the seed fixture.
- N/A — no new or changed API endpoints.
- N/A — no custom Vue component; manifest-declarative widgets only.

## Documentation (company-wide ADR-010)
- N/A — no user-facing feature doc beyond the widget/property labels.

## i18n (company-wide ADR-005)
- New property/widget labels get en/nl catalogue entries per ADR-007/025.

## Compliance
- `openspec validate --change cohort-group-page-polish --strict` passes before this change is marked ready for apply.
- Diff is confined to `lib/Settings/learniq_register.json`, `src/manifest.d/learning.json`, `l10n/*.json`, and the new test file (ADR-031, no PHP/Vue behaviour code).
