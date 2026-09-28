# Role-Aware Dashboards Specification

## ADDED Requirements

### Requirement: Every manifest role-visibility literal MUST resolve to a value the role resolver can emit

`DashboardRoleService::resolvePrimaryRole()` is the single source of `runtime.user.primaryRole`, the value every
`src/manifest.json` `visibleIf.user.primaryRole.in[]` gate is evaluated against. The system MUST guarantee that
the resolver's set of producible values is a superset of every role literal named across every `visibleIf`
gate in the app's effective manifest (base + `manifest.d/*.json` fragments). A `visibleIf` predicate is
fail-safe by construction — any mismatch hides the menu entry rather than erroring — so an unproducible literal
is a silent access-control misrepresentation, not a visible bug: the menu claims delegated access it can never
actually grant. The resolver MUST derive role membership from Nextcloud group membership using the unprefixed
group ids declared by the app's RBAC scope-map configuration (never a `scholiq-`-prefixed convention that no
declaration provisions), checked admin-first. The producible role vocabulary MUST be the canonical,
product-neutral set (`learner`, `instructor`, `team-lead`, `coordinator`, `hr`, `compliance-officer`,
`guardian`, `administration-manager`) shared with the `rbac-declare-groups` change — school-specific words
(`teacher`, `principal`, `mentor`, `parent`) MUST NOT appear as `visibleIf` literals or resolver return values.

#### Scenario: An instructor-group member sees the Learning-analytics trend heatmap
- **GIVEN** a signed-in user who is a member of the `instructors` Nextcloud group and no other privileged group
- **WHEN** they open the Scholiq navigation
- **THEN** the Group Trend Heatmap menu item is visible
- **AND** opening it renders the heatmap for their own cohorts
<!-- @e2e exclude Group-gated nav visibility requires provisioning an `instructors`-only Nextcloud user and logging in as them; the scholiq e2e harness runs a single admin session and cannot switch group membership per test. Verified live instead — see test-plan.md TC-1 for who performs this verification and what is recorded. -->

#### Scenario: A coordinator-group member sees the Engagement configuration items
- **GIVEN** a signed-in user who is a member of the `coordinators` Nextcloud group and no other privileged group
- **WHEN** they open the Scholiq navigation
- **THEN** Point Rules, Engagement Levels, Leaderboards, Point Awards, Engagement Risk Thresholds, and Timetable
  Conflict Queue are all visible
<!-- @e2e exclude Requires a `coordinators` group member session the single-admin scholiq e2e harness cannot provision. Verified live instead — see test-plan.md TC-2. -->

#### Scenario: A guardian-group member sees Book Conference Slots but no staff-only item
- **GIVEN** a signed-in user who is a member of the `guardians` Nextcloud group and no other privileged group
- **WHEN** they open the Scholiq navigation
- **THEN** the Book Conference Slots menu item is visible under Conferences
- **AND** none of the staff-only items (Payments group, Data-exchange group, Engagement group, Compliance,
  Group Trend Heatmap, Engagement Risk Thresholds, Course Evaluation Responses, Conference Schedule Board,
  Timetable Conflict Queue) are visible
<!-- @e2e exclude Requires a `guardians` group member session the single-admin scholiq e2e harness cannot provision. Verified live instead — see test-plan.md TC-3. -->

#### Scenario: A learner with no privileged group membership sees exactly the baseline set
- **GIVEN** a signed-in user with the `learner` role and no membership in `instructors`, `coordinators`,
  `team-leads`, `guardians`, `hr`, `compliance-officers`, or `administration-managers`, and not in the
  Nextcloud admin group
- **WHEN** they open the Scholiq navigation
- **THEN** of the 24 `visibleIf.user.primaryRole`-gated menu items, exactly one is visible — Book Conference
  Slots, via its `learner` literal — and the other 23 are individually confirmed absent
- **AND** My learning remains visible (governed by the separate group-gated dashboard requirement below, not by
  `primaryRole`)
<!-- @e2e exclude Asserts a negative — absence of 23 specific menu entries for a specific group-membership state — which needs a dedicated non-privileged Nextcloud user the single-admin scholiq e2e harness cannot provision. Verified live instead — see test-plan.md TC-4, which names the verifier and requires the exact count and per-item confirmation, not a general "absent" claim. -->

### Requirement: Administrators MUST retain access to every role-gated menu item

A `visibleIf.user.primaryRole.in[]` gate that omits `admin` is unreachable by the one role Nextcloud always
guarantees exists, on every installation, from first boot. The system MUST include `admin` in the `in[]` list
of every menu item gated on `user.primaryRole`, with no exception, so that an administrator can always reach
every feature the app ships regardless of which delegated roles are or are not yet populated with users.

#### Scenario: Admin sees the Compliance item
- **GIVEN** a signed-in user in the Nextcloud admin group
- **WHEN** they open the Scholiq navigation
- **THEN** the Compliance menu item is visible under Insight
- **AND** opening it renders `/apps/scholiq/compliance` with the seeded regulation, attestation, and
  external-training coverage data
<!-- @e2e exclude Server-side admin-group resolution feeding `runtime.user.primaryRole`; verified live on the shared dev instance rather than reproduced as a scholiq DOM-only e2e flow — see test-plan.md TC-5. -->

#### Scenario: Admin sees Book Conference Slots
- **GIVEN** a signed-in user in the Nextcloud admin group
- **WHEN** they open the Scholiq navigation
- **THEN** the Book Conference Slots menu item is visible under Conferences
<!-- @e2e exclude Server-side admin-group resolution feeding `runtime.user.primaryRole`; verified live on the shared dev instance rather than reproduced as a scholiq DOM-only e2e flow — see test-plan.md TC-6. -->

### Requirement: A CI gate MUST reject a manifest role literal the resolver cannot emit, and a group name no declaration provisions

Because a `visibleIf` mismatch is silent in the running app, the guarantee in the two requirements above MUST
be enforced mechanically before merge, not only by manual review. The fleet's manifest cross-reference gate
MUST fail a pull request that either (a) introduces or leaves a `visibleIf.user.primaryRole.in[]` literal in
the effective manifest that the app's role resolver cannot emit, or (b) introduces or leaves an
`IGroupManager::isInGroup()` call site naming a group id that is not declared anywhere the app's RBAC group
collector reads (its OAS scope map or `authorization` blocks).

#### Scenario: Gate fails on a role literal the resolver cannot produce
- **GIVEN** a pull request adds `"in": ["admin", "auditor"]` to a manifest `visibleIf.user.primaryRole` gate
- **AND** `DashboardRoleService::resolvePrimaryRole()` has no path that can return `"auditor"`
- **WHEN** the manifest cross-reference gate runs against the PR diff
- **THEN** the gate reports a `role-resolvable` finding naming the gate's menu item id and the unproducible
  literal
- **AND** the gate's overall status is `failed`

#### Scenario: Gate fails on a group name no declaration provisions
- **GIVEN** a pull request adds a new `isInGroup($uid, 'auditors')` call to `DashboardRoleService`
- **AND** no register/schema `authorization` block or OAS scope map anywhere in the app declares the group id
  `auditors`
- **WHEN** the manifest cross-reference gate runs against the PR diff
- **THEN** the gate reports a `group-declared` finding naming the call site and the undeclared group id
- **AND** the gate's overall status is `failed`

#### Scenario: Gate passes when every literal and every group are accounted for
- **GIVEN** a pull request's manifest names only role literals `DashboardRoleService::resolvePrimaryRole()` can
  emit, and every `isInGroup()` call site names a group declared in the app's RBAC configuration
- **WHEN** the manifest cross-reference gate runs against the PR diff
- **THEN** the `role-resolvable` and `group-declared` checks both report zero findings
- **AND** the gate's overall status is `passed`

## Notes

- These requirements extend `DashboardRoleService`'s role vocabulary from four group-backed roles
  (`compliance-officer`, `hr`, `manager`, `instructor`, backed by never-provisioned `scholiq-*` groups) to
  seven (`compliance-officer`, `hr`, `administration-manager`, `team-lead`, `coordinator`, `instructor`,
  `guardian`, backed by the unprefixed groups `rbac-declare-groups` declares), plus the unconditional `learner`
  fallback and the `admin` short-circuit. `instructor` keeps its pre-fix name (only its backing group id
  changes, from `scholiq-instructor` to `instructors`); `manager` is renamed to `administration-manager`. See
  `openspec/changes/fix-dead-role-gates/design.md` Decisions 1–4 for the full mapping and the rationale — in
  particular, why the fix corrects the MANIFEST's literals onto the resolver's existing neutral vocabulary
  rather than renaming the resolver to match the manifest's (now-retired) school-specific words.
- `finance` is deliberately NOT added to the resolver's producible set — see `design.md` Decision 5 (ADR-081).
  The `FeeItems`/`OrderLines`/`PaymentTransactions`/`Entitlements` gates are corrected to `["admin"]` only.
- `guardian` visibility on `BookConferenceSlotsMenu` is restored, not removed, by this change — see `design.md`
  Decision 6 for the explicit, accepted residual this leaves (a guardian can currently book against any slot,
  not only their own child's, until the later multi-role administration change scopes it).
- The pre-existing "Per-role group-gated dashboard menu items" requirement in this spec governs the three
  top-level dashboard items (Administration/Teaching/My learning) and is unchanged by this delta; the
  requirements added here govern the broader set of role-gated leaf menu items across Insight, Data exchange,
  Student analytics, Engagement, Course evaluation, Conferences, Timetabling, and Payments, all of which read
  the same `runtime.user.primaryRole` value.
