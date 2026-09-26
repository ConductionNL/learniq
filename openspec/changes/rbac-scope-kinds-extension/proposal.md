---
kind: config
---

# Proposal: rbac-scope-kinds-extension

## Summary
`findings.md` row 15.1 credits `lib/Settings/learniq_register.json` with "own-record and role rules" via `x-property-rbac`, noting "own-children scoping only in the portal contribution; no own-group rule." Verifying that claim against OpenRegister's actual `lib/` source (the same method `rbac-declare-groups` used to catch the `x-openregister-authorization` decoy) turns up a second instance of the same class of finding: **`x-property-rbac` is not read by any OpenRegister code path** — grepped across `PermissionHandler.php`, `PropertyRbacHandler.php`, `ConditionMatcher.php`, `Schema.php`, zero literal hits. `rbac-declare-groups`'s own proposal already named `x-property-rbac` as one of the 40 decoy-key schemas in the same breath as `x-openregister-authorization` ("40 carry `x-property-rbac`. Neither key is read by any code path in OpenRegister's lib") — that finding was correct and this register still has it: `DossierNote`'s `x-property-rbac.read.anyOf` (admin/mentor/coordinator + author match) does not even match its own real, enforced `authorization.read` (instructors/compliance-officers), which `PermissionHandler::hasGroupPermission()` actually consults. Extending a confirmed-dead key would be a fabricated guarantee, the exact anti-pattern this register's own comments repeatedly warn against.

This change instead extends the REAL, enforced conditional-authorization grammar — `authorization[action]` entries of shape `{group, match}`, evaluated by `ConditionMatcher`/`OperatorEvaluator` (confirmed live in `PermissionHandler::hasGroupPermission()` and mirrored in `MagicRbacHandler::applyRbacFilters()` for list queries) — with two additive scope-kind conventions beyond the existing own-record (`match: {field: "$userId"}`) pattern: **own-group** (`match: {field: {"$in": "$user.groups"}}`, checking the object's own Nextcloud-group-shaped field against the caller's group memberships) and **care-team** (`match: {field: {"$contains": "$userId"}}`, checking an array-of-user-ids property for membership) — both operators (`$in`, `$contains`) already implemented in `OperatorEvaluator.php` and requiring no OpenRegister change, no cross-object join, and no new PHP in this app.

## Motivation
Two concrete, currently-open gaps this closes:
- `Cohort.ncGroupId` (the Nextcloud group id the cohort-group-provisioning defect fix already writes back on activation, "used for file sharing and permissioning") has no RBAC rule that ever reads it — a co-teacher or parent-helper added to that specific Nextcloud group has no way to read the cohort record itself except via the blanket `instructors`/`hr`/`compliance-officers`/`team-leads` cascade grant, which is far wider than "this one cohort's own group." **Own-group** closes that.
- `DossierNote`'s own `confidentiality` enum already declares a `care-team-only` tier, and its class docblock (`PupilDossierNotesRegisterTest.php`) documents "the finer team-visible/care-team-only/private-to-author tiering beyond that floor is a named, unimplemented OpenRegister platform-capability gap" — but that comment predates the `$contains` operator, whose own docblock states it exists for exactly this: "asks 'is this value one of the object's array members?' — which is what a per-object principal list needs." **Care-team** adds a real, working `careTeamUserIds`-based grant, additive to the existing floor.

## Affected Projects
- [x] Project: `learniq` — `lib/Settings/learniq_register.json` only (two schemas: `Cohort` gains an additive own-group read grant; `DossierNote` gains a new `careTeamUserIds` property and an additive care-team read grant). No PHP, no route, no frontend change.

## Scope

### In Scope
- `Cohort.authorization`: currently `null` (relies on the register's `read-write` cascade: `instructors`/`hr`/`compliance-officers`/`team-leads`). This change makes that cascade explicit on `Cohort` (so nothing already granted is lost) and adds one own-group conditional read entry: `{group: "authenticated", match: {ncGroupId: {"$in": "$user.groups"}}}` — any authenticated user who is a member of that specific cohort's own Nextcloud group (`ncGroupId`) may read it, independent of holding a staff role.
- `DossierNote`: a new `careTeamUserIds` property (array of Nextcloud user ids, default `[]`) and one additive care-team conditional read entry appended to the existing `authorization.read` array: `{group: "authenticated", match: {careTeamUserIds: {"$contains": "$userId"}}}` — any named care-team member may read the note, alongside the existing instructors/compliance-officers/author grants. `create`/`update` are unchanged (staff-only).
- Register-shape PHPUnit tests pinning both new conditional entries' exact key set (`group`, `match.field`/operator/operand) and asserting the cascade groups on `Cohort` are unchanged from what the register-level cascade already granted.

### Out of Scope
- Touching `x-property-rbac` anywhere — it is a confirmed-dead key; this change does not extend, delete, or otherwise imply it is read. Removing the 40 existing `x-property-rbac` blocks fleet-wide is a separate, larger cleanup (same shape as `rbac-declare-groups`'s own `x-openregister-authorization` removal), not this change's job.
- A general-purpose "scope kind" abstraction or documentation page — this change ships two concrete, working applications of the pattern, not a framework.
- Re-tiering `DossierNote`'s three-way `confidentiality` enum into three fully distinct RBAC floors (team-visible vs care-team-only vs private-to-author) — this change adds the care-team grant as one more `anyOf` branch alongside the existing floor; it does not make `confidentiality`'s value itself change which branch applies. A follow-up could do that with an additional `match: {confidentiality: ...}` condition per branch; flagged in Open Questions.
- `SupportRequest`/`LearningPlan` (mentioned in `care-and-support-index`, a different change) — out of scope here.

## Approach
Two additive JSON edits to `lib/Settings/learniq_register.json`, consuming OpenRegister's already-shipped `$in`/`$contains` operators and `$user.groups` dynamic token — no OpenRegister change, no new PHP guard, no migration. Full verification of the operator semantics (read directly from `ConditionMatcher.php`/`OperatorEvaluator.php`) is in design.md.

## New Dependencies
None. Consumes OpenRegister's existing `ConditionMatcher`/`OperatorEvaluator` (`$in`, `$contains`, `$user.groups`) — already shipped, already a dependency.

## Impact
- `lib/Settings/learniq_register.json` — `Cohort.authorization` goes from `null` (implicit cascade) to an explicit block reproducing the cascade plus one own-group entry; `DossierNote` gains one property and one `authorization.read` entry.
- No existing grant is narrowed: `Cohort`'s explicit block reproduces the cascade's exact grantee list, and `DossierNote`'s new entry is additive (appended to the `anyOf`-equivalent list, per `hasGroupPermission()`'s "any entry grants" loop semantics).

## Cross-Project Dependencies
None. Confirmed against OpenRegister's own `lib/` source in the sibling checkout (read-only) rather than assumed from documentation.

## Risks

### Risk 1: Making `Cohort.authorization` explicit could accidentally narrow existing access if the cascade is misread
**Severity:** Medium — **Mitigation:** the register-level cascade (`components.registers.learniq.authorization.roles.read-write`) was read directly from the register JSON before writing `Cohort`'s explicit block (design.md quotes it verbatim); a register-shape test asserts `Cohort.authorization.read`/`create`/`update` contain exactly the same four groups the cascade already granted, so a mismatch fails the suite rather than shipping silently.

### Risk 2: `$user.groups` requires `IGroupManager` to be available to `ConditionMatcher`
**Severity:** Low — **Mitigation:** `ConditionMatcher::resolveUserGroups()` already degrades to an empty array (fail-closed for this condition, not a crash) when no `IGroupManager` was injected, per its own docblock. An own-group grant simply never matches in that configuration; every other grant on `Cohort` is unaffected.

## Rollback Strategy
Revert `lib/Settings/learniq_register.json`. `Cohort` reverts to implicit-cascade `authorization: null` (identical effective grants for the four cascade groups); `DossierNote` loses the `careTeamUserIds` property and its new grant, reverting to today's exact behaviour.

## Open Questions
- Whether `DossierNote`'s three-way `confidentiality` tiering should eventually gate WHICH `anyOf` branch applies (team-visible vs care-team-only vs private-to-author each getting a different floor) rather than all branches being available regardless of `confidentiality` — flagged, not built here (see Out of Scope).
