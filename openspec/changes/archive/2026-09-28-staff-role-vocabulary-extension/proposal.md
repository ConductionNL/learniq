---
kind: config
depends_on: []
---

# Proposal: staff-role-vocabulary-extension

## Summary
Schools name more functions than the seven tags `Staff.roles` carries today. This change adds seven descriptive tags for the counsellor-type and exam functions a Dutch school actually staffs: career counsellor (decaan, loopbaanbegeleider), study adviser (studieadviseur, HE), remedial teacher, care coordinator (intern begeleider, zorgcoördinator), exam secretary (examensecretaris), placement coordinator (stagecoördinator) and confidential counsellor (vertrouwenspersoon). Every tag, old and new, gets a readable label through `x-enum-labels`, so the staff form shows "Exam secretary" instead of `exam-secretary`. No tag grants access: access stays with the eight declared groups, per decision D18.

## Motivation
Round 2 recon E, section 1, found that `Staff.roles` (`lib/Settings/learniq_register.json`, added by #929, commit `0251a06b`) holds only `teacher`, `mentor`, `coordinator`, `teaching-assistant`, `support-staff`, `administrator` and `other`. The same section found no decaan, studieadviseur, examensecretaris or vertrouwenspersoon anywhere in the register, the manifest or the specs. A school that records "who is our exam secretary" or "who is our vertrouwenspersoon" today has to pick `other`, which loses the function.

Decision D18 (Ruben, 2026-09-27) settles the shape: counsellor-type functions are Staff role tags on the existing groups; only the vertrouwenspersoon gets a new confidential scope, and that scope is change 3 of this lane (`confidential-counsellor-channel`), not this one. Assumption A9 says the same. Recon E question 1 recommended option B for exactly this reason: the manifest shows `coordinator`, `instructor` and `administration-manager` already cover every function except the confidential one.

Competitor evidence: round 1 finding 2.14 (staff record: roles, qualifications, availability; evidenced against gibbon, esis and sera). Gibbon, the one open-source competitor read line by line in round 1, models a person's function (`gibbonStaff`, job title) apart from the security role. That is the shape this change keeps: a descriptive function, separate from the RBAC vocabulary in `DashboardRoleService::GROUP_BACKED_ROLES`.

## Affected Projects
- [x] Project: `learniq`: `lib/Settings/learniq_register.json` (`Staff.properties.roles.items` gains seven enum values and an `x-enum-labels` map; `Staff.version` and `info.version` bumped), `lib/Settings/learniq_mock_register.json` (one demo row carrying new tags), `scripts/example-sets/vo.py` and `lib/Settings/profiles/vo.json` (one Staff row with function tags), `l10n/en.json` and `l10n/nl.json` (label keys), `tests/Unit/Settings/SubjectAndTeacherAssignmentRegisterTest.php`, `tests/Unit/Settings/StaffRoleVocabularyRegisterTest.php` (new).

## Scope

### In Scope
- Seven new `Staff.roles` enum values, appended after the existing seven so no stored value changes meaning: `career-counsellor`, `study-adviser`, `remedial-teacher`, `care-coordinator`, `exam-secretary`, `placement-coordinator`, `confidential-counsellor`.
- An `x-enum-labels` map on `Staff.roles.items` for all fourteen values, with Dutch catalogue values in `l10n/nl.json`.
- A property description that says, in plain words, that a tag describes the function and grants no access.
- One Staff row in the secondary school example set (a staff member tagged `career-counsellor` and `exam-secretary`) and one demo row with new tags. The register itself carries no Staff seed rows.

### Out of Scope
- Any new security group, `authorization` block or `visibleIf` value. The confidential scope for the vertrouwenspersoon is change `confidential-counsellor-channel`; the `confidential-counsellor` tag added here is the staff-directory entry only (a school must publish who its vertrouwenspersoon is), and holding it grants nothing.
- Syncing `Staff.roles` to Nextcloud group membership. A tag and a group are kept apart on purpose: a group is enforced, a tag is descriptive.
- Per-role dashboards for the new functions (the role dashboards change deferred distinct widgets already).

## Approach
One declarative edit to the `Staff` schema in the register, labels in the catalogue, seed and demo data, and register-shape tests. No PHP, no route, no manifest change.

## New Dependencies
None.

## Impact
- `Staff` form and index: the roles picker shows fourteen labelled options instead of seven raw codes.
- Existing `Staff` rows are unaffected; every stored value stays valid.
- `SubjectAndTeacherAssignmentRegisterTest` asserted the exact seven-value enum; it now asserts the original seven remain, in order, as a floor.

## Cross-Project Dependencies
None.

## Risks

### Risk 1: A reader mistakes a tag for access
**Severity:** Medium. **Mitigation:** the property description says a tag grants no access, a test pins that no `authorization` block or manifest gate names a `Staff.roles` value, and the one function that needs its own access (vertrouwenspersoon) gets a real group in change 3.

### Risk 2: Parallel lanes bump `info.version` too
**Severity:** Low. **Mitigation:** the bump is a single line; the orchestrator lands PRs in series and resolves the version on merge.

## Rollback Strategy
Revert the commit. The new values are additive; a row that stored a new value would fail validation after a revert, so a rollback after rows exist should first map those rows to `other`.

## Open Questions
None. D18 and A9 answer the scope question recon E raised.
