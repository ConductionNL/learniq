# Tasks: segment-feature-flags

## Implementation Tasks

### Task 1: Add the LearniqSettings schema
- **spec_ref**: `openspec/changes/segment-feature-flags/specs/nextcloud-app/spec.md#requirement-learniqsettings-records-the-instances-segment`
- **files**: `lib/Settings/learniq_register.json` (new `LearniqSettings` schema, slug `learniqsettings`, confirmed free in `contracts/fleet-schema-slugs.json`; register version bump)
- **acceptance_criteria**:
  - GIVEN `LearniqSettings` WHEN read THEN it declares `segment` (enum `po`/`vo`/`mbo`/`he`/`corporate`, default `corporate`), `setBy`/`setAt` (nullable), no lifecycle
- [x] Implement
- [x] Test

### Task 2: Add an admin-only LearniqSettings index+detail page pair
- **spec_ref**: `openspec/changes/segment-feature-flags/specs/nextcloud-app/spec.md#requirement-learniqsettings-is-reachable-as-an-admin-only-declarative-page`
- **files**: `src/manifest.d/compliance.json` (one `admin`-only menu entry under `GroupCompliance`; `LearniqSettings` index page; `LearniqSettingsDetail` detail page with a description note that the segment value does not yet gate any menu — see design.md Risk)
- **acceptance_criteria**:
  - GIVEN the manifest WHEN built THEN `LearniqSettings` appears as an index page with a working detail route, `visibleIf` gated to `admin` only
  - GIVEN `npm run check:manifest` WHEN run THEN it passes
- [x] Implement
- [x] Test

### Task 3: Confirm no visibleIf references segment (documentation task)
- **spec_ref**: `openspec/changes/segment-feature-flags/specs/nextcloud-app/spec.md#requirement-segment-based-menu-visibility-is-not-implemented-by-a-config-kind-change`
- **files**: none (verification-only; the diff itself is the evidence)
- **acceptance_criteria**:
  - GIVEN this change's diff WHEN every changed manifest file is inspected THEN no `visibleIf` block references `segment` or a `workspace.segment`/`config.segment` path
- [x] Implement (verified: no such reference exists in the diff)
- [x] Test (blocked/deferred, documented — see proposal.md Out of Scope and design.md Discovery)

### Task 4: Register-JSON unit test
- **spec_ref**: `openspec/changes/segment-feature-flags/specs/nextcloud-app/spec.md#requirement-learniqsettings-records-the-instances-segment`
- **files**: `tests/Unit/Settings/SegmentFeatureFlagsRegisterTest.php` (new)
- **acceptance_criteria**:
  - GIVEN the test suite WHEN run THEN it asserts `LearniqSettings`'s shape, the `corporate` default, and the absence of a lifecycle block
- [x] Implement
- [x] Test

## Verification
- [x] All tasks checked off
- [x] `openspec validate --changes segment-feature-flags --strict` passes
- [x] `npm run check:manifest`, `npm run check:register`, `npm run check:json-strict` pass
- [x] `vendor/bin/phpunit --filter SegmentFeatureFlagsRegisterTest` passes
- [x] `python3 vendor/conduction/hydra-gates/hydra-gates/scripts/lib/check_schema_property_meta.py lib/Settings/learniq_register.json`, `check_manifest_l10n_coverage.py .`, `check_detail_page_discipline.py`, `check_cross_app_schema_slug.py`, `check_icon_vocabulary.py` all clean before the first push

## Tests (company-wide ADR-009)
- `tests/Unit/Settings/SegmentFeatureFlagsRegisterTest.php` covers `LearniqSettings` shape.
- N/A — no new or changed API endpoints.
- N/A — no custom Vue component; manifest-declarative pages only.
- N/A — "tests on the manifest builder" for `visibleIf` gating: nothing to test, since no gating is added this change (see proposal.md Out of Scope).

## Documentation (company-wide ADR-010)
- N/A — no user-facing feature doc beyond the page/property labels; the detail page's own description text carries the "does not yet gate menus" caveat (Task 2).

## i18n (company-wide ADR-005)
- New schema/property/menu labels get en/nl catalogue entries per ADR-007/025.

## Compliance
- `openspec validate --change segment-feature-flags --strict` passes before this change is marked ready for apply.
- Diff is confined to `lib/Settings/learniq_register.json`, `src/manifest.d/compliance.json`, `l10n/*.json`, and the new test file (ADR-031, no PHP/Vue behaviour code).
