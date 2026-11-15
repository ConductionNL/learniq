# Design: access-control-ratchet-compliance

## Architecture overview

Nothing new is built. The register ratchets under `tests/Unit/Register/` already say what a schema must carry; four schemas that landed in round one do not. This change edits those schemas in `lib/Settings/learniq_register.json`, one constant in each of three lifecycle guards, and the tests that pin them.

| Schema | Before on `development` | After |
|---|---|---|
| `LvsResult` | no `authorization` (cascade: four staff groups read and write, learner reads nothing), `appendOnly: true` | read `coordinators`, `compliance-officers`, learner self; create and update `coordinators`, `compliance-officers`; not append-only |
| `OsoImportDossier` | no `authorization` (cascade) | read, create and update `coordinators`, `compliance-officers` |
| `FirstAidIncident` | read `instructors`, `compliance-officers`; `appendOnly: true` | read adds reporter self; not append-only |
| `DossierNote` | read has author self and care-team entries | unchanged; two tests catch up |

## Decisions

### D1. Read follows the reviewing groups, not the literal `x-property-rbac`

`LvsResult.x-property-rbac` names only admin and the learner. Enforcing exactly that would leave the `verify` guard's own group unable to find a row to verify. The read list is therefore the groups that write and review the row (`coordinators`, `compliance-officers`) plus the learner's own row. `OsoImportDossier.x-property-rbac` names admin and coordinator; `compliance-officers` is added for the same reason, since D23 makes them a writer.

Compared with the cascade, `instructors`, `hr` and `team-leads` lose read on both schemas. `compliance-officers` keep what they had. `coordinators` gain read because the guards name them as the actor. The learner gains read on their own LVS results, which the lvs-import-contract spec asked for.

Alternative considered: `x-property-rbac` literally (learner only on `LvsResult`). Rejected: it breaks the verify flow the same guard exists for.

### D2. Writers are coordinators and compliance-officers (D23)

The cascade writers were `instructors`, `hr`, `compliance-officers`, `team-leads`. On `LvsResult` none of them could update anyway, because `appendOnly` refused it. D23 names coordinators and compliance-officers, the groups that review imports and hold the exam-board role. The nextcloud-app requirement that cascade schemas keep the cascade writers gets an explicit exception for these two import records.

### D3. The guards test the declared group

`coordinator` is not a group the register declares, so nobody is ever in it. `MunicipalityFeedbackGuard` and the rejection guards were renamed to `coordinators` earlier (`295b92c2`); these three were written after that and copied the old word. Each guard test gains a case where a user in `coordinator` is refused, so the singular cannot come back unnoticed.

### D4. appendOnly goes where a lifecycle exists

Open Register runs a transition as an update of the object and refuses every update on an append-only schema. `LvsResult` (`verify`, `archive`) and `FirstAidIncident` (`startHandling`, `resolve`) therefore had dead lifecycles. Both drop the flag, as fifteen schemas did before them. The append-only ratchet's transition list gains one transition for each, so the proof is a run transition and not only a missing flag.

## Declarative-vs-imperative decision

| Behaviour | Path | Rationale |
|---|---|---|
| Who reads and writes the two import records | declarative `authorization` block | OpenRegister enforces it; no code needed |
| Reporter self-read on a first-aid incident | declarative `authorization` entry with `match` | same |
| Who may fire verify, accept, reject | existing imperative guards (ADR-031 exception: lifecycle guard) | the guards already exist; only their group constant changes |

## Security considerations

Access narrows for `instructors`, `hr` and `team-leads` on both import records. It widens for `coordinators` (the lifecycle actor) and for the learner on their own LVS results, both as the specs intend. No delete grant is added; only admins delete. Dropping `appendOnly` lets the writer groups edit an imported LVS score; the audit trail keeps each version, and a freeze listener in the style of `AssessmentResultIntegrityListener` is a named follow-up.

## File structure

```
lib/Settings/learniq_register.json            (LvsResult, OsoImportDossier, FirstAidIncident, info.version)
lib/Lifecycle/LvsResultVerifyGuard.php        (AUTHORISED_GROUPS)
lib/Lifecycle/OsoImportAcceptGuard.php        (AUTHORISED_GROUPS)
lib/Lifecycle/OsoImportRejectGuard.php        (AUTHORISED_GROUPS)
src/manifest.d/pupil-record.json              (_note only)
tests/Unit/Register/ImportRecordAccessTest.php          (new)
tests/Unit/Register/DeclaredAudienceEnforcedTest.php
tests/Unit/Register/LifecycleSchemasAreNotAppendOnlyTest.php
tests/Unit/Settings/RbacScopeKindsRegisterTest.php
tests/Unit/Settings/LvsResultRegisterTest.php
tests/Unit/Lifecycle/LvsResultVerifyGuardTest.php
tests/Unit/Lifecycle/OsoImportAcceptGuardTest.php
tests/Unit/Lifecycle/OsoImportRejectGuardTest.php
```

## Seed data

No schema is added and no property changes. `FirstAidIncident` keeps its existing seed rows; `LvsResult` and `OsoImportDossier` have none on `development` and arrive through imports. Gate 101 only asks for seed rows on new schemas.

## Trade-offs

Following the literal `x-property-rbac` would have been simpler to explain and would have broken the verify flow. Keeping the cascade writers would have kept the nextcloud-app requirement untouched and ignored D23.
