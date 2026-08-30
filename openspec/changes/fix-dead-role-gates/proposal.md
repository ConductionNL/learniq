---
kind: code
depends_on: [rbac-declare-groups]
---

# Proposal: fix-dead-role-gates

## Summary

`src/manifest.json` gates 24 menu entries on `visibleIf: {"user.primaryRole": {"in": [...]}}`, naming ten role
values. `lib/Service/DashboardRoleService::resolvePrimaryRole()` — the service that supplies
`runtime.user.primaryRole` to the manifest renderer — can only ever return six values, admin-first. Six named
values (`coordinator`, `finance`, `mentor`, `parent`, `principal`, `teacher`) can never be produced, which
silently locks 17 menu items to admin-only despite claiming delegation, and hides 2 menu items (Compliance,
BookConferenceSlots) from admin entirely because their gates omit it. A second, mirror-image defect sits
alongside it: two of the resolver's SIX producible values (`instructor`, `manager`) are never named by ANY
manifest gate — dead resolver output. This change corrects the manifest's role literals onto a canonical,
product-neutral role vocabulary (shared with the sibling `rbac-declare-groups` change ahead of the
Scholiq→Learniq reframing), points `DashboardRoleService` at the unprefixed group ids that vocabulary
declares, opens a door for administrators on the two admin-invisible entries, restores guardian visibility on
conference booking, and adds a CI gate so a manifest role literal can never again outrun what the resolver can
emit.

## Motivation

A `visibleIf` predicate is fail-safe by construction: any mismatch between the literal it names and the value
the resolver can produce hides the menu entry rather than erroring, so this whole defect class is silent. It
was found only by comparing the manifest's ten named role values against the resolver's six possible outputs
and confirming live on the shared dev instance (`localhost:8080`, logged in as `admin`) that `loadState('scholiq',
'primaryRole')` returns `"admin"` while the nav's 23 top-level entries contain no "Compliance" — even though
`/apps/scholiq/compliance` itself renders correctly with real seeded data (1 regulation, 1 attestation, 1
external-training record). The page works; the door is missing. The same mismatch makes 17 items that read as
role-delegated in the manifest actually admin-only in practice, which misrepresents the app's RBAC posture to
anyone reviewing `src/manifest.json` as documentation of who can reach what. Nothing catches this today: gate 22
(`manifest-validation`) checks the manifest against its JSON Schema, and gate 30
(`effective-manifest-crossref`) checks menu-route/page/slug/deepLink joins — neither checks a role literal
against what the PHP resolver can actually emit.

A joint review with the `rbac-declare-groups` change (2026-08-19) additionally found that the resolver's two
unnamed outputs (`instructor`, `manager`) are the SAME defect in reverse, and settled the vocabulary both
changes converge on — see Scope and `design.md` for the resulting corrections.

## Affected Projects

- [ ] Project: `scholiq` — `src/manifest.json` (13 of 24 `visibleIf.user.primaryRole` gates corrected onto the
  canonical vocabulary), `lib/Service/DashboardRoleService.php` (role vocabulary + unprefixed group ids), 2
  admin-visibility fixes (Compliance, BookConferenceSlots) plus guardian visibility restored on
  BookConferenceSlots, associated PHPUnit coverage
- [ ] Project: `hydra` — extend gate 30 (`effective-manifest-crossref`) with a `role-resolvable` check (manifest
  role literal → resolver-producible value) and a `group-declared` check (PHP `isInGroup()` call → declared
  group id)

## Scope

### In Scope

1. Add an administrator door to the two menu entries currently invisible to admin: `Compliance` (gate
   `["compliance-officer", "hr"]` → add `"admin"`) and `BookConferenceSlotsMenu` (see item 2 for its full new
   gate).
2. Reconcile the role vocabulary so no `visibleIf` literal names a value `DashboardRoleService::resolvePrimaryRole()`
   cannot emit, using the canonical, product-neutral table below (binding on this change and
   `rbac-declare-groups`):

   | role (resolver emits) | group (declared + provisioned) | retired school-only literal it replaces |
   |---|---|---|
   | `learner` | `learners` | *(already producible; unchanged)* |
   | `instructor` | `instructors` | *(name unchanged — only its group id moves; was dead, now consumed)* |
   | `team-lead` | `team-leads` | `mentor` |
   | `coordinator` | `coordinators` | *(already named in the manifest; was unproducible, now producible)* |
   | `hr` | `hr` | *(already producible; unchanged)* |
   | `compliance-officer` | `compliance-officers` | *(already producible; unchanged)* |
   | `guardian` | `guardians` | `parent` |
   | `administration-manager` | `administration-managers` | `principal`, and the resolver's own dead `manager` |

   `finance` is removed from the four Payments gates entirely (left as `["admin"]`) — see design.md Decision 5
   (ADR-081: Payments is leaving Scholiq to Pipelinq; no domain app books income). The manifest's `visibleIf`
   literals are corrected to the `role` column above wherever they named a retired school-only word; the
   resolver's `instructor` keeps its existing name.
3. Point `DashboardRoleService` at the unprefixed group ids in the table above instead of the `scholiq-*`
   groups that have never existed on the instance.
4. Extend hydra gate 30 (`effective-manifest-crossref`) with two new cross-reference checks: every
   `visibleIf.user.primaryRole` literal in the assembled manifest resolves to a value the resolver can emit,
   and every group name `DashboardRoleService` (or any other in-app `isInGroup()` call site) tests membership
   of is declared somewhere `RbacGroupCollector` reads.

### Out of Scope

- Declaring the OpenRegister `authorization` blocks or the OAS scope map that provisions Nextcloud groups —
  that is `rbac-declare-groups`. Per joint review, `rbac-declare-groups` has been instructed to declare all
  eight table entries above (including `learners`, which `DashboardRoleService` itself does not check — see
  design.md Decision 3). This change's dependency on that declaration is load-bearing for four of the seven
  group-backed roles it introduces or retargets — see design.md Decision 4.
- Replacing the single-scalar `primaryRole` model with multi-role administration memberships (a later change).
  `guardian`'s visibility is restored on `BookConferenceSlotsMenu` by this change (see design.md Decision 6),
  but WHICH slots a specific guardian may book against a specific child remains unscoped until that later
  change — an explicit, documented residual, not a silent gap.
- Menu restructuring — `nav-restructure-dashboards` (open, in-flight) moves `Compliance` and other entries
  between groups. This change edits gate conditions and role literals in place; it does not reorder or
  relocate menu items. The two changes touch overlapping regions of `src/manifest.json` and may need a rebase
  against each other at merge time.
- Any change to `RoleSelector` (the `LearnerProfile.primaryRole` calculation service) or the `LearnerProfile.roles`
  schema enum. `DashboardRoleService` and `RoleSelector` are two independent resolvers that happen to share
  historical naming for `compliance-officer`/`hr`/`instructor`/`manager`/`learner`; only `DashboardRoleService`
  feeds menu `visibleIf`, and after this change its vocabulary for the renamed/added roles permanently
  diverges from `RoleSelector`'s (documented, not an inconsistency to reconcile later).
- The education-vs-corporate menu LABEL text (rightmost two columns of the vocabulary table in design.md).
  This change fixes machine-facing identifiers (the `visibleIf` literal, the group id); tenant-aware label
  switching is a separate, later feature.

## Approach

`DashboardRoleService::resolvePrimaryRole()` changes from a positional list of NC-group-suffix strings to an
explicit role → unprefixed-group-id map: `compliance-officer` → `compliance-officers`, `hr` → `hr`,
`administration-manager` → `administration-managers` (renamed from `manager`), `team-lead` → `team-leads`
(new), `coordinator` → `coordinators` (new), `instructor` → `instructors` (name unchanged, group retargeted),
`guardian` → `guardians` (new). `resolveViews()`'s role-tier checks are updated in lockstep. `src/manifest.json`
corrects every gate that named a retired school-only literal (`teacher`, `principal`, `mentor`, `parent`) onto
the matching canonical role, gains `admin` on the two admin-invisible gates, and drops `finance` from the four
Payments gates. Gate 30's crossref checker gains two new check classes that statically read the resolver's
producible-value set and every declared group id, and fail the same way its existing menu-route/action-target
checks do. Full detail in `design.md`.

## New Dependencies

None.

## Impact

- `lib/Service/DashboardRoleService.php` — role vocabulary and group-id lookup table
- `src/manifest.json` — 13 of the 24 role-gated `visibleIf` blocks change their `in[]` list content (full
  before/after table in `tasks.md` Task 4); the remaining 11 gates are unchanged in content but become
  reachable for the first time (or stay correctly admin-only) once the resolver's vocabulary matches
- `tests/Unit/Service/DashboardRoleServiceTest.php` — role-string assertions updated to the canonical
  vocabulary, plus new coverage for `team-lead`/`coordinator`/`guardian` and a no-privileged-group refusal case
- hydra `conduction/hydra-gates` package — `scripts/lib/check_manifest_crossref.js` (gate 30) gains two checks;
  `scripts/test-fixtures/effective-manifest/` gains fixtures for both

## Cross-Project Dependencies

Depends on `rbac-declare-groups` (this repo): that change declares all eight canonical group ids in the OAS
scope map so `GroupProvisioner` creates them as real Nextcloud groups. This change cannot be verified
end-to-end (a real user actually seeing a gated menu entry) until `rbac-declare-groups` merges and the groups
are provisioned on the target instance — see `design.md` Decision 4 for exactly which four groups this change
newly relies on that declaration for, and the Migration Plan for how deployment order is sequenced around it.

Also touches `hydra` (the gate-30 extension) — a fleet-shared gate change, reviewed and merged independently
of the scholiq-side manifest/service edits, though both land in this change's task list since the gate is what
prevents this defect class recurring.

## Risks

### Risk 1: `guardian` visibility without per-child scoping is a real, accepted interim over-grant

**Severity:** Medium — **Mitigation:** Documented explicitly rather than solved by hiding the feature
(design.md Decision 6). A guardian who can see `BookConferenceSlotsMenu` can, until the later multi-role
administration change lands, book against any exposed slot rather than only their own child's. This is bounded
(still requires `guardian`/`admin`/`learner` group membership; not open to every authenticated user) and is a
narrower exposure than the pre-fix state (which hid the feature from every guardian, unconditionally — the
same silent-denial defect class this whole change fixes). The residual MUST carry forward into the later
administrations change's scope rather than being forgotten once this change ships.

### Risk 2: `administration-managers`/`team-leads`/`coordinators`/`guardians` groups may not exist on any
instance until `rbac-declare-groups` deploys

**Severity:** Medium — **Mitigation:** `IGroupManager::isInGroup()` on a group that does not exist yet returns
`false` — the same "role never selected" behavior every non-admin role already has today, for everyone, before
this change. No user is locked OUT of anything they had before; these four roles are simply unreachable until
`rbac-declare-groups` (confirmed, per joint review, to declare all eight) deploys, or an operator creates the
groups manually via Nextcloud's Users admin page in the interim.

### Risk 3: `nav-restructure-dashboards` (open, parallel) touches overlapping manifest regions

**Severity:** Low — **Mitigation:** The two changes edit different attributes of the same JSON nodes (this
change: `visibleIf.user.primaryRole.in[]`; that change: entry placement/grouping). A textual conflict is
plausible at merge time but not a semantic one; whichever change lands second rebases the diff.

## Rollback Strategy

Every edit is declarative or a small, self-contained PHP class change: revert `lib/Service/DashboardRoleService.php`
and the 13 changed `visibleIf` blocks in `src/manifest.json` to restore prior (broken) behavior. The gate-30
extension can be reverted independently in the `hydra` repo without affecting scholiq. No data migration, no
schema change, no seed-data change is part of this proposal, so rollback carries no data-loss risk.

## Open Questions

- Should `coordinator`/`team-lead` get their own `resolveViews()` dashboard-view tier instead of sharing the
  operational-staff tier with `administration-manager`/`instructor`? Deferred as a product-UX question — see
  design.md's final Open Questions section.

The naming-convention question (plural-kebab groups, `hr` as the accepted irregular) and the group-provisioning
dependency question that were previously open here are both resolved by the joint review recorded above and in
`design.md` Decisions 2 and 4.
