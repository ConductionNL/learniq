# Test Plan: pok-signature-parent-role

All regression tests; each is run against `development` first, where it must fail. No live instance for this lane.

### TC-1: A minor's agreement waits for a parent
- **spec_ref**: `openspec/changes/pok-signature-parent-role/specs/bpv/spec.md#requirement-pok-activation-is-gated-on-every-required-signature`
- **type**: regression
- **preconditions**: placement with a learner born 2009-04-30; student signed 2025-08-22; school and praktijkopleider signed
- **steps**: `PokActivationGuard::check()`
- **expected result**: deny, message names a parent or guardian listed on the learner profile (old code: allow)
- **test command**: `vendor/bin/phpunit --filter PokActivationGuardTest`

### TC-2: A listed parent completes it; a self-declared parent does not
- **spec_ref**: same requirement
- **type**: regression
- **steps**: add a `parent` signature from a user in `parentIds`, then from one who is not
- **expected result**: allow; deny
- **test command**: `vendor/bin/phpunit --filter PokActivationGuardTest`

### TC-3: Adult, unknown age, and a birthday after signing
- **spec_ref**: same requirement
- **type**: regression
- **steps**: learner aged 19 at signing; learner without a birth date; learner who signed at 17 and is 18 at activation
- **expected result**: allow; deny with the date-of-birth message; deny
- **test command**: `vendor/bin/phpunit --filter "PokActivationGuardTest|PokParentSignatureRuleTest"`

### TC-4: The stamp action writes the flag
- **spec_ref**: `.../specs/bpv/spec.md#scenario-the-signing-flow-asks-for-the-parent`
- **type**: regression
- **steps**: `PokParentSignatureStampAction::execute()` for a 16-year-old and a 19-year-old
- **expected result**: `parentSignatureRequired` true, false
- **test command**: `vendor/bin/phpunit --filter PokParentSignatureStampActionTest`

### TC-5: The register carries the role, the flag and the calculation
- **spec_ref**: `.../specs/bpv/spec.md#requirement-three-party-pok-signing-reuses-the-signature-pattern-via-poksignature`
- **type**: regression
- **steps**: read the shipped register
- **expected result**: `parent` in the enum, `parentSignatureRequired` declared, `isFullySigned` requires the parent clause, the stamp action on `requestSignatures` and `activate`, versions bumped
- **test command**: `vendor/bin/phpunit --filter PokParentRoleRegisterTest`

### TC-6: The signing page offers the role and says why
- **spec_ref**: `.../specs/bpv/spec.md#scenario-the-signing-flow-asks-for-the-parent`
- **type**: regression
- **steps**: `SIGNABLE_SUBJECTS.praktijkovereenkomst.roles`, `parentSignatureNeeded()`
- **expected result**: `parent` offered; the note shows only for a POK with the flag set
- **test command**: `node --test tests/unit-js/customPages.test.mjs`

### TC-7: The MBO set follows the rule
- **type**: regression
- **steps**: `VocationalCollegeExampleSetTest::testPlacementsAreSignedVisitedAndAssessed`
- **expected result**: every agreement a minor signed carries `parentSignatureRequired: true` and a parent signature from a listed parent; the rest carry neither
- **test command**: `vendor/bin/phpunit --filter VocationalCollegeExampleSetTest`

## Coverage Summary

| Requirement | Covered by |
|---|---|
| Three-party POK signing reuses the Signature pattern (parent role) | TC-5, TC-6 |
| POK activation is gated on every required signature | TC-1, TC-2, TC-3, TC-4, TC-7 |

## Out of Scope

A browser run of the signing page: no instance for this lane. The page change is a role option and a note card, covered at the helper level.
