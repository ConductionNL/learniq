# Test Plan: segment-wizard-choice

## Test Cases

### TC-1: A valid descriptor passes the contract
- **spec_ref**: `openspec/changes/segment-wizard-choice/specs/example-sets/spec.md#requirement-an-example-set-is-one-descriptor-file-per-segment`
- **type**: regression
- **preconditions**: `tests/fixtures/profiles/fixture.json` and every `lib/Settings/profiles/*.json`
- **steps**: run `ExampleSetDescriptorContractTest`
- **expected result**: keys, id, namespace, unique uuids and slugs, resolved references, objectCount, schema validity all pass
- **test command**: `vendor/bin/phpunit --filter ExampleSetDescriptorContractTest`

### TC-2: Broken descriptors are rejected with a named defect
- **spec_ref**: `openspec/changes/segment-wizard-choice/specs/example-sets/spec.md#requirement-an-example-set-is-one-descriptor-file-per-segment`
- **type**: regression
- **preconditions**: in-memory copies of the fixture with a dangling reference, a foreign uuid, a wrong objectCount, a missing required property
- **steps**: run the contract's checker on each copy
- **expected result**: each copy yields a finding naming the object and property
- **test command**: `vendor/bin/phpunit --filter ExampleSetDescriptorContractTest`

### TC-3: Listing orders the sets and survives a broken file
- **spec_ref**: `openspec/changes/segment-wizard-choice/specs/example-sets/spec.md#requirement-the-wizard-lists-the-shipped-sets-next-to-the-generated-one`
- **type**: functional
- **preconditions**: a temp app dir with `po.json`, `vo.json`, one malformed file, and a mock register
- **steps**: `SeedProfileService::listChoices()`
- **expected result**: `none, po, vo, demo`; a warning for the malformed file
- **test command**: `vendor/bin/phpunit --filter SeedProfileServiceTest`

### TC-4: Loading imports exactly the descriptor
- **spec_ref**: `openspec/changes/segment-wizard-choice/specs/example-sets/spec.md#requirement-loading-a-set-imports-exactly-its-descriptor`
- **type**: functional
- **preconditions**: `example_profile = po`; a mocked `ConfigurationService`
- **steps**: `runAction('load-example-set')`
- **expected result**: `importFromApp` gets `learniq.profile.po` and the file; the answer names the count; a path answer is 400
- **test command**: `vendor/bin/phpunit --filter 'SetupControllerTest|SeedProfileServiceTest'`

### TC-5: Removal hands the exact uuids to the purge command
- **spec_ref**: `openspec/changes/segment-wizard-choice/specs/example-sets/spec.md#requirement-a-loaded-set-can-be-removed-exactly`
- **type**: functional
- **preconditions**: a fake `openregister:objects:purge` command registered in a console application
- **steps**: run `learniq:example-set:remove po --apply`, then without `--apply`, then with `demo`
- **expected result**: reverse-ordered uuids with `--force` (and `--apply` only when given); `demo` exits non-zero
- **test command**: `vendor/bin/phpunit --filter ExampleSetRemoveCommandTest`

### TC-6: The segment answer writes LearniqSettings
- **spec_ref**: `openspec/changes/segment-wizard-choice/specs/example-sets/spec.md#requirement-the-wizard-asks-what-kind-of-organisation-this-is`
- **type**: functional
- **preconditions**: no row, then one row
- **steps**: `saveConfig` with `segment: po`, then `vo`, then `kindergarten`
- **expected result**: create, update of the same uuid, 400 with nothing written; status reports the step done once a row exists
- **test command**: `vendor/bin/phpunit --filter 'SetupControllerTest|SegmentServiceTest'`

### TC-7: The manifest keeps welcome and the offer first
- **spec_ref**: `openspec/changes/segment-wizard-choice/specs/example-sets/spec.md#requirement-the-wizard-asks-what-kind-of-organisation-this-is`
- **type**: regression
- **preconditions**: `src/manifest.json`
- **steps**: `check_setup_demo_first.py`, `check_manifest_l10n_coverage.py`, `npm run check:manifest`
- **expected result**: all pass; the segment step carries `suggestFrom: example_profile`
- **test command**: the three commands above

## Coverage Summary
- An example set is one descriptor file per segment: TC-1, TC-2.
- The wizard lists the shipped sets next to the generated one: TC-3.
- Loading a set imports exactly its descriptor: TC-4.
- A loaded set can be removed exactly: TC-5.
- The wizard asks what kind of organisation this is: TC-6, TC-7.

## Out of Scope
A live import and purge against an instance: lanes do not touch the shared instance on :8080. `demo-data-setup-step.spec.ts` is updated to the new ids so CI exercises the live path.
