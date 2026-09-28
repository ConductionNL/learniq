# Tasks: po-schooladvies-flow

## Implementation Tasks

### Task 1: Add the SchoolAdvies schema
- **spec_ref**: `openspec/changes/po-schooladvies-flow/specs/enrolment/spec.md#requirement-persist-schooladvies-domain-objects-in-openregister`
- **files**: `lib/Settings/learniq_register.json`
- **acceptance_criteria**:
  - GIVEN the register WHEN a `SchoolAdvies` is created with `voorlopigAdviesLevel` set THEN it persists in `lifecycle: voorlopig` with `isVoorlopigOverdue`/`isDefinitiefOverdue` calculations declared
- [x] Implement
- [x] Test

### Task 2: Add SchoolAdviesFinalizeGuard
- **spec_ref**: `openspec/changes/po-schooladvies-flow/specs/enrolment/spec.md#requirement-a-po-schooladvies-may-only-be-raised-on-heroverweging-never-lowered-unless-motivated`
- **files**: `lib/Lifecycle/SchoolAdviesFinalizeGuard.php`, `tests/Unit/Lifecycle/SchoolAdviesFinalizeGuardTest.php`
- **acceptance_criteria**:
  - GIVEN a higher doorstroomtoets result with no raise/motivation WHEN `vaststellenDefinitief` is attempted THEN it is refused
  - GIVEN the definitief level raised to match, OR a motivation given, OR the pro/vmbo-bb exemption applies WHEN attempted THEN it succeeds
  - GIVEN the doorstroomtoets result does not outrank definitief WHEN attempted THEN it always succeeds
- [x] Implement
- [x] Test

### Task 3: Add SchoolAdviesSendToRodHandler
- **spec_ref**: `openspec/changes/po-schooladvies-flow/specs/enrolment/spec.md#requirement-sending-a-definitief-schooladvies-to-rod-auto-queues-the-existing-bron-rod-dataexchangejob`
- **files**: `lib/Listener/SchoolAdviesSendToRodHandler.php`, `lib/AppInfo/Application.php`, `tests/Unit/Listener/SchoolAdviesSendToRodHandlerTest.php`
- **acceptance_criteria**:
  - GIVEN a `SchoolAdvies` in `definitief` WHEN `verzendenNaarRod` fires THEN a `DataExchangeJob` (`target: bron-rod`, `scope.schema: school-advies`) is created and its UUID is stamped onto `dataExchangeJobId`
- [x] Implement
- [x] Test

### Task 4: Add SchoolAdvies manifest index+detail pages, and seed data
- **spec_ref**: `openspec/changes/po-schooladvies-flow/specs/enrolment/spec.md#requirement-frontend-is-declarative-with-manifest-indexdetail-pages`
- **files**: `src/manifest.d/learning.json` (or the matching domain fragment), `lib/Settings/learniq_mock_register.json`
- **acceptance_criteria**:
  - GIVEN the manifest is built WHEN navigating to the new pages THEN `SchoolAdvies`/`SchoolAdviesDetail` exist as declarative pages with no PHP controller
  - GIVEN a fresh install WHEN demo data loads THEN 3 `SchoolAdvies` objects exist, spanning voorlopig/definitief/verzonden-naar-rod
- [x] Implement
- [x] Test

### Task 5: Add SchoolAdviesRegisterTest
- **spec_ref**: `openspec/changes/po-schooladvies-flow/specs/enrolment/spec.md#requirement-persist-schooladvies-domain-objects-in-openregister`
- **files**: `tests/Unit/Settings/SchoolAdviesRegisterTest.php`
- **acceptance_criteria**:
  - GIVEN the register JSON WHEN parsed THEN the `SchoolAdvies` schema block, its lifecycle transition table, and its calculation expressions are well-formed and match the spec
- [x] Implement
- [x] Test

## Quality checklist

- All new PHP classes covered by PHPUnit unit tests (`tests/Unit/`)
- No new API endpoints — OpenRegister's generic object API serves `SchoolAdvies`
- UI changes are declarative manifest pages only, no bespoke Vue component to browser-test
- All tests pass: `vendor/bin/phpunit --filter SchoolAdvies`
- Dutch (`nl_NL`) and English (`en_US`) translation strings added for the new manifest page titles/
  labels and enum display labels (ADR-007); no em-dashes, no Title Case
- `openspec validate --change po-schooladvies-flow` passes
