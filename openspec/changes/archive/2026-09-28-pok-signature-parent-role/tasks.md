# Tasks: pok-signature-parent-role

Every task writes its test first and runs it against the old code, where it must fail.

## Implementation Tasks

### Task 1: Register: parent role, parentSignatureRequired, isFullySigned, stamp action
- **spec_ref**: `openspec/changes/pok-signature-parent-role/specs/bpv/spec.md#requirement-three-party-pok-signing-reuses-the-signature-pattern-via-poksignature`
- **files**: `lib/Settings/learniq_register.json`, `tests/Unit/Settings/PokParentRoleRegisterTest.php`, `l10n/en.json`, `l10n/nl.json`
- **acceptance_criteria**:
  - `parent` in `PokSignature.signerRole`; `Praktijkovereenkomst.parentSignatureRequired` declared; `isFullySigned` requires a parent when the flag is set
  - the stamp action is declared on `requestSignatures` and `activate`; `PokSignature` and `Praktijkovereenkomst` at 0.2.0; `info.version` bumped
  - new strings have English and Dutch catalogue values
- [x] Implement
- [x] Test

### Task 2: PokParentSignatureRule decides whether a parent signs and who may
- **spec_ref**: `openspec/changes/pok-signature-parent-role/specs/bpv/spec.md#requirement-pok-activation-is-gated-on-every-required-signature`
- **files**: `lib/Service/PokParentSignatureRule.php`, `tests/Unit/Service/PokParentSignatureRuleTest.php`
- **acceptance_criteria**:
  - GIVEN a learner under 18 on the student's signing date WHEN evaluated THEN required, reason minor, the profile's parentIds
  - GIVEN no birth date or no reachable profile THEN required, reason unknown-age; GIVEN 18 or older THEN not required
- [x] Implement
- [x] Test

### Task 3: PokActivationGuard requires a listed parent's signature when the rule says so
- **spec_ref**: `openspec/changes/pok-signature-parent-role/specs/bpv/spec.md#requirement-pok-activation-is-gated-on-every-required-signature`
- **files**: `lib/Lifecycle/PokActivationGuard.php`, `tests/Unit/Lifecycle/PokActivationGuardTest.php`
- **acceptance_criteria**:
  - GIVEN a minor's three signatures WHEN activated THEN denied; GIVEN a listed parent's signature too THEN allowed
  - GIVEN a parent signature from an unlisted user THEN denied; GIVEN an adult's three signatures THEN allowed
- [x] Implement
- [x] Test

### Task 4: PokParentSignatureStampAction writes the flag on requestSignatures and activate
- **spec_ref**: `openspec/changes/pok-signature-parent-role/specs/bpv/spec.md#scenario-the-signing-flow-asks-for-the-parent`
- **files**: `lib/Lifecycle/Action/PokParentSignatureStampAction.php`, `tests/Unit/Lifecycle/Action/PokParentSignatureStampActionTest.php`
- **acceptance_criteria**:
  - GIVEN a 16-year-old's POK WHEN signatures are requested THEN `parentSignatureRequired: true`; GIVEN a 19-year-old THEN false
- [x] Implement
- [x] Test

### Task 5: The signing page offers the parent role and says when a parent signs
- **spec_ref**: `openspec/changes/pok-signature-parent-role/specs/bpv/spec.md#scenario-the-signing-flow-asks-for-the-parent`
- **files**: `src/utils/customPages.js`, `src/views/SignatureView.vue`, `tests/unit-js/customPages.test.mjs`, `l10n/`
- **acceptance_criteria**:
  - the POK role list includes `parent`; the note shows only for a POK with `parentSignatureRequired: true`
- [x] Implement
- [x] Test

### Task 6: The MBO example set follows the rule
- **spec_ref**: `openspec/changes/pok-signature-parent-role/design.md#seed-data`
- **files**: `scripts/example-sets/mbo.py`, `lib/Settings/profiles/mbo.json`, `tests/Unit/Settings/VocationalCollegeExampleSetTest.php`
- **acceptance_criteria**:
  - every agreement a minor signed has the flag and a listed parent's signature; no existing uuid moves; the generator check passes
- [x] Implement
- [x] Test

## Verification
- [x] `openspec validate pok-signature-parent-role` passes
- [x] Diff-scoped checks, then `composer check:strict`, `npm run lint`, `npm run format`, `npm run check:schema-l10n`, hydra gates, each with its exit code in the PR body

## Quality checklist

- Business logic covered by PHPUnit tests that fail on the old code
- No new endpoint, so no Newman test; the page change is covered at helper level
- Dutch catalogue values for every new string (ADR-007); no em-dashes, sentence case
