# Design: pok-signature-parent-role

## Architecture Overview

```
requestSignatures / activate ──▶ PokParentSignatureStampAction ──┐
                                                                  ├─▶ PokParentSignatureRule
activate (guard) ─────────────▶ PokActivationGuard ──────────────┘      │
                                                                         ├─ BpvPlacement (find, no RBAC)
                                                                         ├─ LearnerProfile (learnerRef, else LearnerRefResolver)
                                                                         └─ the student's PokSignature.signedAt
```

One rule, two callers. `PokParentSignatureRule::evaluate(array $pok, ?string $studentSignedAt)` returns whether a parent signs, why (`minor`, `unknown-age`, `adult`), and the `parentIds` that may sign. The guard uses it to decide; the stamp action uses it to write `Praktijkovereenkomst.parentSignatureRequired`, which the signing page and `isFullySigned` read.

## Decisions

### D1. Age at the student's own signature decides

"Minor at signing" is measured on the date of the student's `PokSignature.signedAt`: the student's consent is the one that needs a representative. A student who signs at 17 and turns 18 before activation still needs the parent. Before the student has signed (the stamp on `requestSignatures`), the rule measures today. Alternative considered: the placement start date. Rejected: the agreement binds from signing, not from the first working day.

### D2. The guard derives, the flag informs

The guard never reads `parentSignatureRequired`: any client that can write the POK could set it to false. The flag exists for the frontend and for `isFullySigned`, and the action rewrites it on `requestSignatures` and `activate` from the same rule, so after activation it matches what the guard decided.

### D3. Unknown age fails closed

No date of birth, or no profile reachable through the placement, means the rule answers `required: true, reason: unknown-age`. The parent signature protects minors, and a guard that skips it for missing data protects nobody. The refusal text tells the coordinator to record the date of birth when the student is 18 or older.

### D4. Only a listed parent counts

A `parent` signature counts only when its `signerId` is in the student's `LearnerProfile.parentIds`, the rule `LearningPlanSignatureGuard` applies to learning plans (#180). A minor without a linked parent account cannot be activated, and the refusal names the learner profile as the place to fix it.

### D5. Reads run without RBAC

The placement and profile reads pass `_rbac: false`: the coordinator activating the POK may not read `LearnerProfile`, and only the birth date and `parentIds` are used. The profile is found through `BpvPlacement.learnerRef` when present, else through `LearnerRefResolver::resolve(learnerId)`, the resolver that change `learnerrefs-backfill-and-lookup-dedupe` keeps.

### D6. Refusal messages name what is missing

| State | Message |
|---|---|
| a student, school or workplace trainer signature missing | the existing sentence, plus the parent sentence when a parent is required |
| three present, student under 18 at signing, no listed parent signed | "The student was under 18 when they signed, so a parent or guardian listed on their learner profile also signs the practical training agreement." |
| three present, age unknown, no listed parent signed | "The student's date of birth is not recorded, so a parent or guardian listed on their learner profile also signs the practical training agreement. Record the date of birth if the student is 18 or older." |

### D7. The signing page asks

`SIGNABLE_SUBJECTS.praktijkovereenkomst.roles` gains `parent` (label "Parent or guardian", already in the view). A new `parentSignatureNeeded(kind, subject)` helper in `customPages.js` tells the view to show a note: "A parent or guardian also signs this agreement. The student is under 18, or their date of birth is not recorded." The POK detail page's data widget shows `parentSignatureRequired` with no manifest change.

## Declarative-vs-imperative decision (ADR-031)

| Behaviour | Path | Rationale |
|---|---|---|
| `isFullySigned` with the parent clause | declarative | `x-openregister-aggregate-refs.parentSignatureCount` plus an `or` in the calculation |
| `parentSignatureRequired` | imperative, lifecycle action | needs a cross-schema read (placement, profile, signature date); OpenRegister calculations cannot follow a `$ref` two hops |
| Activation gate | imperative, lifecycle guard | existing ADR-031 exception (`PokActivationGuard`), extended |

## Mixed-spec rationale

`kind: code`. The register edits (one enum value, one property, one aggregate, one expression clause, two action declarations) only mean something with the guard and action that implement them.

## Nextcloud Integration

- Lifecycle: `PokActivationGuard` (`LifecycleGuardInterface`), `PokParentSignatureStampAction` (`LifecycleActionInterface`), both resolved from the container.
- Services: `PokParentSignatureRule` (new, `ObjectService`, `LearnerRefResolver`, `ITimeFactory`).

## Security Considerations

The guard trusts nothing the client wrote: not the flag, not a claimed `parent` role. Reads without RBAC return only a birth date and a list of user ids to the guard; nothing leaves the server.

## File Structure

```
lib/Service/PokParentSignatureRule.php                  new
lib/Lifecycle/Action/PokParentSignatureStampAction.php  new
lib/Lifecycle/PokActivationGuard.php                    parent requirement
lib/Settings/learniq_register.json                      PokSignature 0.2.0, Praktijkovereenkomst 0.2.0
src/utils/customPages.js, src/views/SignatureView.vue
scripts/example-sets/mbo.py, lib/Settings/profiles/mbo.json
tests/Unit/Service/PokParentSignatureRuleTest.php, tests/Unit/Lifecycle/PokActivationGuardTest.php,
tests/Unit/Lifecycle/Action/PokParentSignatureStampActionTest.php, tests/Unit/Settings/PokParentRoleRegisterTest.php,
tests/unit-js/customPages.test.mjs, tests/Unit/Settings/VocationalCollegeExampleSetTest.php
```

## Seed Data

No new schema, so no new mock rows; gate 101 validates the existing mock `PokSignature` and `Praktijkovereenkomst` rows unchanged. The MBO example set follows the rule:

| Schema | Rows | Change |
|---|---|---|
| `learner-profile` | every student under 18 on the first school day | `parentIds: ["mbo-ouder-NNN"]`, the parent already named as emergency contact |
| `praktijkovereenkomst` | 151 | `parentSignatureRequired`, true where the student was under 18 on the day they signed (72) |
| `pok-signature` | +72, appended after the existing 453 so no uuid moves | `signerRole: parent`, signed a day after the student, before the placement starts |

The generator adds these without new random draws, so every other value in the set stays as it was.

## Trade-offs

- Failing closed on an unknown age can block an adult's agreement until the birth date is recorded. That is a data fix the refusal names, and cheaper than an unsigned minor's agreement.
- The flag duplicates what the guard derives. It is the price of a declarative read surface; D2 keeps it from becoming a bypass.
