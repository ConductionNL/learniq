---
kind: config
depends_on: []
---

# Proposal: bpv-coach-authorization

## Summary
`BpvPlacement` and `Praktijkopleider` carry no `authorization` block, so OpenRegister falls back to the register cascade: `instructors`, `hr`, `compliance-officers` and `team-leads` read and write every row, and nobody else does. The stagecoördinator in the `coordinators` group sees the BPV menu but gets an empty list, and the placement's own `schoolCoachId` decides nothing. This change gives both schemas an explicit block: the cascade groups keep what they had, `coordinators` and `administration-managers` (the menu audience) gain read, `coordinators` gain create and update, and on `BpvPlacement` the named school coach and the learner each read their own placement.

## Motivation
Round 2 recon E, section 1, row "BPV/work-placement staffing": `src/manifest.d/work-placement.json` gates the BPV group to `instructor`, `coordinator`, `administration-manager` and `admin`, while `Praktijkopleider` and `BpvPlacement` have no RBAC block and fall through to the cascade, "unlike every other schema discussed above". Proposed-changes row `bpv-coach-authorization`: make `schoolCoachId` an enforced scope rather than an implicit default.

The cascade is documented in `tests/Unit/Register/DeclaredAudienceEnforcedTest.php`: it "lets the four staff groups read every row and gives a learner no read on their own rows". `coordinators` is not one of the four. So a coordinator-only stagecoördinator hits two dead ends today: the placements index is empty, and `WerkprocesGradeEmitHandler::loadObject()` (which reads the placement under the acting user) finds nothing when that coordinator confirms a werkproces assessment.

Decision D18 keeps counsellor-type functions on the existing groups; the stagecoördinator is `coordinators`. This change makes that group actually reach the BPV records its menu promises.

Competitor evidence: round 1 `proposed-rows.md` S-new-1 and S-new-3 (`_round1/compare/proposed-rows.md` lines 144 and 146). Eduarte and OSIRIS register BPV centrally ("BPV's en overeenkomsten centraal geregeld", OSIRIS kernregistratie), and OSIRIS ties portal access to the relation itself: "access tied to the student-praktijkopleider relation" (osiris.nu, Bedrijvenportaal). The relation-scoped access this change adds for the school coach is the staff-side twin of that rule.

## Affected Projects
- [x] Project: `learniq`: `lib/Settings/learniq_register.json` (`BpvPlacement.authorization`, `Praktijkopleider.authorization`, both `version`s, `info.version`), `tests/Unit/Settings/BpvCoachAuthorizationRegisterTest.php` (new).

## Scope

### In Scope
- `BpvPlacement.authorization`: read by `instructors`, `hr`, `compliance-officers`, `team-leads`, `coordinators`, `administration-managers`, the user named in `schoolCoachId`, and the user named in `learnerId`; create and update by `instructors`, `hr`, `compliance-officers`, `team-leads` and `coordinators`; no delete entry (admin only).
- `Praktijkopleider.authorization`: read by the same six groups; create and update by the same five writer groups. No self-match: a praktijkopleider has no Nextcloud account (the schema description says so; portal access runs through portaliq).
- A register test that pins both blocks and the self-matches.

### Out of Scope
- `Praktijkovereenkomst`, `WerkprocesAssessment`, `BpvVisitReport` and `PokSignature` stay on the cascade. They reference the placement by id, and an `authorization` match can only compare a field on the same row, so the coach scope cannot follow the join. A follow-up can give them the same group set; `BpvVisitReport` could also carry the `schoolCoachId` self-match, since it has that field.
- Portal reads. portaliq reads these schemas with `_rbac: false` and applies its own scope (`PortalContributionProvider::practicalTrainerContribution()`), so nothing changes for a praktijkopleider.
- A new group for the stagecoördinator (D18: the function is a Staff tag, the access is `coordinators`).

## Approach
Two declarative `authorization` blocks in the register, using the grammar the register already enforces (group strings plus `{"group": "authenticated", "match": {...}}` entries, as on `SupportRequest` and `Portfolio`). No PHP.

## New Dependencies
None.

## Impact
- A `coordinators` or `administration-managers` member now sees placements and workplace supervisors.
- A coach who is in none of those groups still sees the placements that name them; a learner sees their own placement.
- Nobody loses access: the four cascade groups are listed explicitly.

## Cross-Project Dependencies
None. portaliq's reads bypass RBAC by design.

## Risks

### Risk 1: The learner self-match exposes placement fields a learner should not see
**Severity:** Low. **Mitigation:** the placement is the learner's own record (company, period, coach); `trainingCompanyVerification` holds the SBB lookup result, which the learner may see. Portal projection already hides it from the praktijkopleider, not from the learner.

### Risk 2: `administration-managers` read a supervisor's contact details
**Severity:** Low. **Mitigation:** the BPV menu already shows them the pages; without read they see empty lists. Contact details of a workplace supervisor are business contact data.

## Rollback Strategy
Revert the commit; both schemas fall back to the cascade.

## Open Questions
None.
