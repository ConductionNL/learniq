---
kind: code
depends_on: []
---

# Proposal: confidential-counsellor-channel

## Summary
A school's vertrouwenspersoon (confidential counsellor) needs somewhere to keep case notes that no school leader, mentor, coordinator or compliance officer can open. Learniq has no such place: `DossierNote.confidentiality` is presentational, and its enforced read floor includes `instructors` and `compliance-officers`. This change adds one declared scope, `confidential-counsellors`, and one new schema, `ConfidentialNote`, readable only by its author (while in that group) and by the people the author names on the case. The scope runs through all three layers the app enforces roles in: the register's declared groups, `DashboardRoleService`, and the manifest menu.

## Motivation
Round 2 recon E, section 1: no schema, scope, menu entry or spec for a vertrouwenspersoon exists anywhere in learniq. Section 3: a vertrouwenspersoon has a duty of confidentiality, so the notes must be "structurally excluded from the pupil dossier that a school leader, mentor or compliance-officer can read, not just labelled confidential and left to role discipline". Round 1 finding 2.12 already recorded that `DossierNote.confidentiality` is presentational and the enforced floor is role RBAC. Section 6 names the governance risk: folding the vertrouwenspersoon into an existing broad scope such as `compliance-officers` would show confidential material to people the sector's guidance says must not see it.

Decision D18 (Ruben, 2026-09-27): "one new confidential scope for the vertrouwenspersoon with notes no school leader or compliance officer can read", and "every new scope must pass the register ratchet tests". Assumption A9 says the same. Recon E question 1, option B.

Sources for the rule itself: School & Veiligheid, "Geheimhouding in het vertrouwenswerk" and SBO, "Vertrouwenspersoon in het onderwijs" (both read 2026-09-27, cited in recon E section 3). Practice there is to destroy the case file once the complaint process closes.

## Affected Projects
- [x] Project: `learniq`: `lib/Settings/learniq_register.json` (new scope, new `ConfidentialNote` schema with seed row, `info.version`), `lib/Settings/learniq_mock_register.json` (demo rows), `lib/Service/DashboardRoleService.php` (role `confidential-counsellor`, `isConfidentialCounsellor()`), `lib/Controller/PageController.php` (one initial-state value), `src/main.js` (runtime flag), `src/manifest.d/confidential-counsel.json` (menu entry, index and detail pages), `l10n/`, tests.

## Scope

### In Scope
- Scope `confidential-counsellors` in `components.securitySchemes.oauth2.flows.authorizationCode.scopes`, so OpenRegister provisions the group.
- `ConfidentialNote` (slug `confidential-note`): case title, note, author, named participants, status (open or closed), a destroy-after date, tenant. Not searchable, hard delete. No `$ref` to or from any other schema, so no pupil record, related panel or report can lead to it.
- `ConfidentialNote.authorization`: read by a `confidential-counsellors` member who is the author, and by any user listed in `participantIds`; create by `confidential-counsellors`; update and delete by the author while in that group. No other group appears anywhere in the block.
- `DashboardRoleService`: `confidential-counsellor` joins `GROUP_BACKED_ROLES` below every staff role, so a teacher who is also the vertrouwenspersoon keeps the teacher menus, and an external vertrouwenspersoon with no other group resolves to `confidential-counsellor` instead of `learner`. `isConfidentialCounsellor()` answers group membership.
- A top-level "Confidential notes" menu entry, gated on `user.isConfidentialCounsellor`, with index and detail pages.

### Out of Scope
- The annual anonymised report a vertrouwenspersoon writes, and any complaint-committee workflow.
- Automatic destruction on the destroy-after date. The date is a reminder; the author deletes.
- Purging OpenRegister's audit trail on delete (a platform setting, see Risks).
- Descriptive tagging of who the vertrouwenspersoon is: that is the `confidential-counsellor` Staff tag in `staff-role-vocabulary-extension`, which grants nothing.

## Approach
Declarative first: the scope, the schema and its `authorization` block live in the register, in the grammar OpenRegister enforces (`MagicRbacHandler::processConditionalRule()`: the user qualifies for the group, then the same-row match must hold). The code part is the thin glue that lets the menu see the role, because the manifest predicate grammar has no OR and `primaryRole` is a single value.

## New Dependencies
None.

## Impact
- Nav: members of `confidential-counsellors` see one extra top-level entry. Nobody else sees a change.
- Role resolution: users in `confidential-counsellors` and no staff group resolve to `confidential-counsellor` (student dashboard view) instead of `learner`.
- No existing schema changes.

## Cross-Project Dependencies
None. OpenRegister provisions the new group from the declared scope, as it does for the other eight.

## Risks

### Risk 1: Nextcloud admins bypass OpenRegister RBAC
**Severity:** High. **Mitigation:** named, not hidden. OpenRegister returns every row to the `admin` group (`MagicRbacHandler`, the admin short-circuit). A school must keep the Nextcloud `admin` group to IT staff who are not school leaders. The design, the schema description and the docs say so.

### Risk 2: The audit trail keeps a deleted note's content
**Severity:** Medium. **Mitigation:** OpenRegister writes the old object into its audit trail on delete (`DeleteObject`), and the only switch is the instance-wide `auditTrailsEnabled` retention setting. Named in the design as a follow-up for OpenRegister: a per-schema audit opt-out.

### Risk 3: An author names the wrong person as participant
**Severity:** Low. **Mitigation:** the field description says a participant can read the note; only the author can change the list.

## Rollback Strategy
Revert the commit. The group stays provisioned (OpenRegister creates, never deletes), with no rule referencing it. Notes already written stay in OpenRegister under a schema the app no longer declares; a rollback after use should first have the authors export or delete their notes.

## Open Questions
None.
