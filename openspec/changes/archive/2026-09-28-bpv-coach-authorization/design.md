# Design: bpv-coach-authorization

## Architecture Overview
OpenRegister enforces a schema's `authorization` block and never reads `x-property-rbac` (openregister#4064, pinned in `tests/Unit/Register/DeclaredAudienceEnforcedTest.php`). A schema without a block falls through to the register cascade: `instructors`, `hr`, `compliance-officers`, `team-leads` read and write, nobody else. This change writes the block for the two BPV schemas that recon E found without one.

## Nextcloud Integration
- Controllers: none.
- Services: none. `WerkprocesGradeEmitHandler::loadObject()` and `CompetencyAttainmentRollupHandler` read `bpv-placement` under the acting user; adding `coordinators` to read lets a coordinator's confirm reach the placement.
- Mappers/Entities: none.
- Events/Hooks: none.

## Decisions

### D1: Keep the four cascade groups explicitly
`DeclaredAudienceEnforcedTest::testWritesKeepTheCascadeGrants` set the register convention: when a schema moves off the cascade, it keeps exactly the grants the cascade gave, then adds. Following it means no current user loses access. Alternative considered: least privilege (drop `hr`). Rejected for this change: it is a tightening nobody asked for, and a BPV placement is MBO-only, so a corporate `hr` member rarely has rows to see.

### D2: Add the menu audience, not more
`work-placement.json` shows the BPV group to `instructor`, `coordinator`, `administration-manager` and `admin`. Read gains `coordinators` and `administration-managers`; writes gain `coordinators` only. A school leader reads, the stagecoördinator acts.

### D3: The coach and the learner are self-match reads
`schoolCoachId` and `learnerId` are Nextcloud user ids (their descriptions say so). Each gets a `{"group": "authenticated", "match": {"<field>": "$userId"}}` read entry, the same shape `SupportRequest.raisedBy` and `Portfolio.learnerId` use, so `testEverySelfMatchIsEnforced` and `testEveryMatchedFieldIsAPropertyOfItsSchema` see well-formed entries. `learnerRef` (a portal identity uuid) and `practicalTrainerId` (a Praktijkopleider uuid) are not user ids and get no entry.

### D4: No coach write scope
A coach who can edit the placement that names them could reassign `schoolCoachId`. Writes stay with groups; a coach who is also a teacher already writes through `instructors`.

### Declarative-vs-imperative decision
| Behaviour | Path | Rationale |
|---|---|---|
| Row-level read scope for coach and learner | Declarative, `authorization` block | The register grammar expresses a same-row match; no service needed. |

## Security Considerations
Tightens nothing and widens read to two groups plus two self-matches. The self-matches are same-row, fail-closed matches: a user who is not named sees nothing. Delete stays admin-only (no `delete` key). Portal access is unchanged: portaliq reads with `_rbac: false` and applies its own scope.

## File Structure
```
lib/Settings/learniq_register.json                       BpvPlacement + Praktijkopleider authorization, versions
tests/Unit/Settings/BpvCoachAuthorizationRegisterTest.php  new
```

## Seed Data
No new seed rows. Both schemas already exist and already have demo rows in `lib/Settings/learniq_mock_register.json` (gate 101). Their `x-openregister-seed` arrays are empty, and a `BpvPlacement` seed would need `LearnerProfile` and `CurriculumPlan` seeds the register does not carry.

## Trade-offs
The child schemas (POK, werkproces assessment, visit report, signature) stay on the cascade, so a coordinator-only user reads placements but not their POK. Following the placement join needs a relation-aware rule OpenRegister does not have; giving those four the group set without the coach scope is a small follow-up, named in the proposal.
