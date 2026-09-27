# Tasks: segment-wizard-choice

Stacked on `segment-runtime-bridge` (uses `SegmentService` and the six-value enum).

## Implementation Tasks

### Task 1: SegmentService writes the segment and lists the six kinds (must, MVP)
- **spec_ref**: `openspec/changes/segment-wizard-choice/specs/example-sets/spec.md#requirement-the-wizard-asks-what-kind-of-organisation-this-is`
- **files**: `lib/Service/SegmentService.php`, `tests/Unit/Service/SegmentServiceTest.php`
- **acceptance_criteria**:
  - GIVEN no row WHEN `setSegment('po', 'admin')` THEN one LearniqSettings row is created with segment, setBy and setAt
  - GIVEN a row WHEN `setSegment('vo', 'admin')` THEN that row's uuid is updated, no second row
  - GIVEN an unknown code WHEN `setSegment` THEN it throws and writes nothing
  - GIVEN `listChoices()` WHEN read THEN six entries in enum order, each with id, label, description, icon
- [x] Implement
- [x] Test

### Task 2: SeedProfileService lists, loads and names the uuids of a set (must, MVP)
- **spec_ref**: `openspec/changes/segment-wizard-choice/specs/example-sets/spec.md#requirement-the-wizard-lists-the-shipped-sets-next-to-the-generated-one`
- **files**: `lib/Service/SeedProfileService.php`, `lib/Settings/profiles/.gitkeep`, `lib/Service/DemoDataService.php` (card label), `tests/Unit/Service/SeedProfileServiceTest.php`, `tests/Unit/Service/DemoDataServiceTest.php`
- **acceptance_criteria**:
  - GIVEN two descriptors WHEN listed THEN `none`, sets by order, then `demo`
  - GIVEN a malformed file WHEN listed THEN it is skipped with a warning
  - GIVEN `install('po')` WHEN run THEN importFromApp gets config id `learniq.profile.po` and the file's data
  - GIVEN `uuidsFor('po')` WHEN run THEN every object uuid, last-loaded first; `demo` and unknown ids throw
- [x] Implement
- [x] Test

### Task 3: The descriptor contract test (must, MVP)
- **spec_ref**: `openspec/changes/segment-wizard-choice/specs/example-sets/spec.md#requirement-an-example-set-is-one-descriptor-file-per-segment`
- **files**: `tests/Unit/Settings/ExampleSetDescriptorContractTest.php`, `tests/fixtures/profiles/fixture.json`
- **acceptance_criteria**:
  - GIVEN every `lib/Settings/profiles/*.json` and the fixture WHEN checked THEN keys, id, namespace, unique uuids and slugs, resolved references, objectCount and schema validity (required, enum, format, pattern, type) all hold
  - GIVEN a broken copy of the fixture (dangling reference, foreign uuid, wrong count) WHEN checked THEN each defect is reported
- [x] Implement
- [x] Test

### Task 4: SetupController serves profiles and segments and stores both answers (must, MVP)
- **spec_ref**: `openspec/changes/segment-wizard-choice/specs/example-sets/spec.md#requirement-loading-a-set-imports-exactly-its-descriptor`
- **files**: `lib/Controller/SetupController.php`, `tests/Unit/Controller/SetupControllerTest.php`
- **acceptance_criteria**:
  - GIVEN status WHEN read THEN version 2, `profiles`, `segments`, steps `example-set`, `load-example-set`, `segment`
  - GIVEN `example_profile` (or legacy `demo_dataset`) WHEN saved THEN only offered ids are stored; a path is refused with 400
  - GIVEN `segment` WHEN saved THEN SegmentService writes it with the admin as setBy; an unknown code is 400
  - GIVEN `load-example-set` / `skip-example-set` and the legacy aliases WHEN run THEN they import, decline or refuse as before
- [x] Implement
- [x] Test

### Task 5: occ learniq:example-set:remove (must, MVP)
- **spec_ref**: `openspec/changes/segment-wizard-choice/specs/example-sets/spec.md#requirement-a-loaded-set-can-be-removed-exactly`
- **files**: `lib/Command/ExampleSetRemoveCommand.php`, `appinfo/info.xml`, `tests/Unit/Command/ExampleSetRemoveCommandTest.php`
- **acceptance_criteria**:
  - GIVEN `po --apply` WHEN run THEN the purge command gets the set's uuids, `--force` and `--apply`
  - GIVEN no `--apply` WHEN run THEN the purge command runs as a dry run
  - GIVEN `demo`, an unknown id, or no purge command WHEN run THEN it exits non-zero with a reason
- [x] Implement
- [x] Test

### Task 6: Wizard steps and copy (must, MVP)
- **spec_ref**: `openspec/changes/segment-wizard-choice/specs/example-sets/spec.md#requirement-the-wizard-asks-what-kind-of-organisation-this-is`
- **files**: `src/manifest.json`, `l10n/en.json`, `l10n/nl.json` (+ `npm run l10n:build`)
- **acceptance_criteria**:
  - GIVEN the manifest WHEN built THEN steps are welcome, example-set, load-example-set, segment, done; version 2; gate 100 passes
  - GIVEN every new step title, body and card string WHEN looked up THEN en and nl carry it (gate 102)
- [x] Implement
- [x] Test

### Task 7: CI seed, e2e spec and docs follow the new contract (should, V1)
- **spec_ref**: `openspec/changes/segment-wizard-choice/specs/example-sets/spec.md#requirement-loading-a-set-imports-exactly-its-descriptor`
- **files**: `tests/e2e/ci-seed.sh`, `tests/e2e/spec-coverage/demo-data-setup-step.spec.ts`, `docs/installation.md`
- **acceptance_criteria**:
  - GIVEN ci-seed WHEN it settles the wizard THEN it also answers the segment step with `corporate`
  - GIVEN the e2e spec WHEN it picks a set THEN it reads `profiles`, posts `example_profile`, runs `load-example-set`, expects step `example-set`
  - GIVEN docs WHEN read THEN they name the two wizard questions and the remove command
- [x] Implement
- [x] Test

## Verification
- [x] All tasks checked off
- [x] `openspec validate segment-wizard-choice --strict` passes
- [x] Diff-scoped checks green (php -l, phpcs, phpstan, phpmd, phpunit filter, eslint, check:specs, check:manifest, schema-l10n, manifest-l10n, setup-demo-first)
- [ ] `composer check:strict`, `npm run lint`, `npm run format`, hydra gates with `--base origin/development` run once before push

## Quality checklist
- New PHP covered by unit tests with at least three methods each.
- API endpoints unchanged in routing; their contract changes are covered by `SetupControllerTest` and the updated e2e spec (not runnable in this lane: no shared instance).
- i18n: every new wizard and card string in en and nl.
