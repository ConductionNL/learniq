---
kind: config
depends_on: []
---

# Proposal: access-control-ratchet-compliance

## Summary

Four round-one schemas shipped on `development` without meeting the access ratchets under `tests/Unit/Register/`, and six of learniq's twelve red tests are theirs. This change applies the ratchets' own rules to them: `LvsResult` and `OsoImportDossier` get enforced `authorization` blocks, `FirstAidIncident` enforces its reporter's self-read, the three import guards test the declared `coordinators` group, and `LvsResult` and `FirstAidIncident` stop being append-only because they have a lifecycle. Two tests that pin `DossierNote`'s read audience learn the care-team entry that `rbac-scope-kinds-extension` added. Access gets stricter or stays equal for every group except the one each lifecycle names as its actor. That follows decision D23 (Ruben, 2026-09-27).

## Motivation

OpenRegister enforces a schema's `authorization` block and never reads `x-property-rbac` (openregister#4064). A schema that declares its audience only in `x-property-rbac` falls through to the register cascade. The cascade lets `instructors`, `hr`, `compliance-officers` and `team-leads` read every row, and gives the learner no read on their own row.

On `development` at `a84b6273` the full PHPUnit suite reports twelve failures. Six of them are this change's:

| Test | What it found |
|---|---|
| `DeclaredAudienceEnforcedTest::testNoSchemaDeclaresAnAudienceWithoutAnAuthorizationBlock` | `LvsResult`, `OsoImportDossier` have `x-property-rbac` and no block |
| `DeclaredAudienceEnforcedTest::testEverySelfMatchIsEnforced` | `LvsResult.learnerId`, `FirstAidIncident.reportedBy` self-reads not enforced |
| `DeclaredAudienceEnforcedTest::testGroupOnlyBlocksAddThePersonTheRowIsAbout` | pins `DossierNote.read` without the care-team entry |
| `GuardGroupsAreDeclaredTest::testEveryGroupAGuardTestsIsDeclared` | `LvsResultVerifyGuard`, `OsoImportAcceptGuard`, `OsoImportRejectGuard` test `coordinator`, a group nobody provisions |
| `LifecycleSchemasAreNotAppendOnlyTest::testNoSchemaIsAppendOnlyAndTransitions` | `LvsResult`, `FirstAidIncident` are append-only with transitions |
| `RbacScopeKindsRegisterTest::testDossierNoteGainsCareTeamPropertyAndReadEntry` | expects one conditional read entry on `DossierNote`, there are two |

The consequences are real, not cosmetic. No coordinator can verify an imported LVS result or accept a transfer dossier, because the guards look for a group that does not exist. No first-aid incident can be moved to in-handling or resolved, because Open Register refuses every update on an append-only schema. A pupil cannot read their own normed test results.

The other six red tests (seed counts, target descriptions, the attendance recipients) are landing repairs, handled by `round1-landing-repairs`, which stacks on this change.

## Affected Projects

- [x] Project: `learniq` — register JSON (three schemas), three lifecycle guards, their tests and the ratchet tests

## Scope

### In Scope

- `LvsResult.authorization`: read by `coordinators` and `compliance-officers` plus the learner's own row (`learnerId`); create and update by `coordinators` and `compliance-officers`; no delete entry.
- `OsoImportDossier.authorization`: read by `coordinators` and `compliance-officers`; create and update by `coordinators` and `compliance-officers`. No learner self-read: `learnerEckId` is not a Nextcloud user, and the dossier describes a pupil who has no account here yet.
- `FirstAidIncident.authorization.read` gains `{group: authenticated, match: {reportedBy: $userId}}`. Its groups stay as they are.
- `LvsResultVerifyGuard`, `OsoImportAcceptGuard`, `OsoImportRejectGuard`: `AUTHORISED_GROUPS` becomes `admin`, `coordinators`; their tests use `coordinators` and add a case proving the singular `coordinator` is refused.
- `LvsResult` and `FirstAidIncident` drop `appendOnly`; `LvsResultRegisterTest` and the append-only ratchet's transition list follow.
- `DeclaredAudienceEnforcedTest` and `RbacScopeKindsRegisterTest` pin `DossierNote.read` with both conditional entries (author and care team).
- A new `ImportRecordAccessTest` pins the two new blocks exactly.
- Version bumps: the three schemas to `0.2.0`, `info.version` to `0.29.0`.

### Out of Scope

- The six landing-repair failures (`round1-landing-repairs`).
- An integrity listener that freezes an imported LVS score after import. Dropping `appendOnly` lets the two writer groups edit a score; before, nobody could, but nobody could verify either. Named as a follow-up.
- `x-property-rbac` stays as written: OpenRegister does not read it, and the enforced block is what this change sets.

## Approach

Declarative register patches only, plus one constant per guard. Details in design.md.

## New Dependencies

None.

## Impact

- `lib/Settings/learniq_register.json`: three schemas.
- `lib/Lifecycle/LvsResultVerifyGuard.php`, `OsoImportAcceptGuard.php`, `OsoImportRejectGuard.php`: the group constant.
- Tests under `tests/Unit/Register/`, `tests/Unit/Settings/` and `tests/Unit/Lifecycle/`.
- `src/manifest.d/pupil-record.json`: the `_note` on `FirstAidIncidentDetail` that still says append-only.

## Cross-Project Dependencies

None. OpenRegister already enforces `authorization` blocks and `$userId` matches.

## Risks

### Risk 1: A coordinator gains read on LVS results and transfer dossiers
**Severity:** Medium — **Mitigation:** the verify, accept and reject transitions name coordinators as their actor, so they must find the row. Every other group reads the same or less than under the cascade.

### Risk 2: A writer edits an imported LVS score
**Severity:** Low — **Mitigation:** only `coordinators` and `compliance-officers` update, and OpenRegister's audit trail keeps every version. A freeze listener is named as a follow-up.

### Risk 3: Another round-three lane touches the same schemas
**Severity:** Low — **Mitigation:** `round1-landing-repairs` stacks on this branch. The PR body names the schemas so the landing can order them.

## Rollback Strategy

Revert the merge commit. The next register import restores the old blocks.
