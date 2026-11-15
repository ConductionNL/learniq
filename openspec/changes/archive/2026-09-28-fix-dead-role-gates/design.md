# Design: fix-dead-role-gates

## Architecture Overview

`src/manifest.json` declares navigation-item visibility declaratively via `visibleIf.user.primaryRole.in[]`,
evaluated client-side by the nextcloud-vue manifest renderer against `runtime.user.primaryRole` — initial state
`DashboardRoleService::resolvePrimaryRole()` provides server-side. The renderer treats an unresolved or
non-matching predicate as "hide"; there is no error path. This is the correct default for a public-facing
UI (never show a raw failure to a user), but it means a naming mismatch between the manifest's literals and
the resolver's vocabulary produces no symptom anywhere except "the menu item that should be there, isn't" —
exactly the kind of defect that survives code review, schema validation (gate 22), and even the existing
manifest cross-reference gate (gate 30), because none of them cross-check a role STRING against a PHP
function's possible return values.

```
┌─────────────────────────┐        provides         ┌──────────────────────────┐
│ DashboardRoleService     │ ───────────────────────▶ │ runtime.user.primaryRole │
│ ::resolvePrimaryRole()   │  (initial state, PHP)     │ (frontend, manifest ctx) │
└─────────────────────────┘                            └──────────────┬───────────┘
        ▲                                                              │ evaluated by
        │ checks membership in                                        ▼
┌─────────────────────────┐                            ┌──────────────────────────┐
│ Nextcloud groups:         │                            │ src/manifest.json         │
│ instructors, hr,          │                            │ visibleIf.user.primaryRole│
│ compliance-officers,      │                            │ .in[...]  × 24 gates      │
│ team-leads, coordinators, │                            └──────────────────────────┘
│ guardians,                │
│ administration-managers   │
│ (`learners` also declared,│
│  not checked here — see   │
│  Decision 2)              │
└─────────────────────────┘
        ▲
        │ auto-provisioned by (all 8 declared groups; see Decision 4)
┌─────────────────────────┐
│ RbacGroupCollector +      │
│ GroupProvisioner (OR)     │  ← declared by rbac-declare-groups (dependency)
└─────────────────────────┘
```

## Revision note (binding coordinator review, 2026-08-19)

This document was reviewed against the sibling `rbac-declare-groups` change and revised before implementation
began. **The revision reverses the direction of the original Decision 1** (see below) and adds `guardian` as a
new producible role. The discovery that drove the original decision — `instructor` and `manager` are resolver
outputs no manifest gate ever named, the mirror image of the defect this change fixes — stands unchanged and
is still what motivated closing that gap in the same change. Only the DIRECTION of the fix changed.

## Goals / Non-Goals

**Goals**
- Every `visibleIf.user.primaryRole` literal in `src/manifest.json` names a value `resolvePrimaryRole()` can
  actually return, using the **canonical, product-neutral role vocabulary** below (binding on both this change
  and `rbac-declare-groups`).
- Every menu item gated on `user.primaryRole` includes `admin`.
- `DashboardRoleService` checks membership in the same unprefixed group ids `rbac-declare-groups` provisions.
- A CI gate makes this class of defect (manifest names a value the resolver can't emit; PHP tests membership
  of a group nothing declares) fail a pull request instead of shipping silently.

**Non-Goals**
- Multi-role / per-relation administration (a guardian's link to a specific learner, a user holding more than
  one role simultaneously with per-role UI). `resolvePrimaryRole()` stays single-scalar, admin-first,
  priority-ordered — this change only widens its vocabulary and retargets its group ids. See Decision 6 for
  the specific, explicitly-accepted residual this leaves on the guardian path.
- Touching `RoleSelector` or the `LearnerProfile.roles` schema enum. That calculation service answers a
  different question ("what role does this LearnerProfile record's own declared `roles` array support,
  cross-checked against group membership so a learner can't self-elevate") for a different consumer (a
  calculated OpenRegister field), and nothing in the defect being fixed here touches it. Its own vocabulary
  (`compliance-officer`, `hr`, `admin`, `manager`, `instructor`, `learner`) is untouched by this change and is
  now permanently DIFFERENT from `DashboardRoleService`'s (see Decision 1) — an accepted, documented divergence
  between two independent resolvers, not an inconsistency to "fix" later.
- Declaring the education/corporate DISPLAY label switching (the two rightmost columns of the vocabulary table
  below). This change fixes the `visibleIf` literal and its backing group id — both machine-facing identifiers.
  Whether the Compliance/Payments/etc. menu LABEL text should read differently for a school tenant vs. a
  corporate tenant is a separate, later feature; today's manifest label text is unchanged by this change.

## Canonical role/group vocabulary (binding on this change and `rbac-declare-groups`)

Set by joint review, 2026-08-19, ahead of the Scholiq→Learniq reframing (a learning system for schools AND
companies/training providers). The `role` column is what `resolvePrimaryRole()` returns and what every
manifest `visibleIf.user.primaryRole.in[]` MUST name; the `group` column is the unprefixed Nextcloud group id
`rbac-declare-groups` provisions. Roles are singular-kebab, groups are plural-kebab; `hr` is the one accepted
irregular (an initialism, and the spelling `hrmq`/`shillinq` already share).

| role (resolver emits) | group (declared + provisioned) | education label | corporate label |
|---|---|---|---|
| `learner` | `learners` | Pupil / Student | Employee |
| `instructor` | `instructors` | Teacher | Trainer |
| `team-lead` | `team-leads` | Mentor | Team lead |
| `coordinator` | `coordinators` | Year coordinator | L&D coordinator |
| `hr` | `hr` | Staff office | HR |
| `compliance-officer` | `compliance-officers` | Compliance officer | Compliance officer |
| `guardian` | `guardians` | Parent / guardian | (off in corporate) |
| `administration-manager` | `administration-managers` | Principal | L&D manager |

School-specific words (`teacher`, `principal`, `mentor`, `parent`) that the pre-fix manifest named are
retired as ROLE/GROUP identifiers, not as UI copy — the "education label" column is exactly the vocabulary a
school-tenant's menu SHOULD keep showing; only the underlying machine identifier moves to the neutral term.
`finance` stays dropped entirely (Decision 5, unchanged from the original review — ADR-081: Payments is
leaving Scholiq, no domain app books income).

## Decisions

### Decision 1: Correct the manifest to name the resolver's existing, neutral vocabulary — do not rename the resolver to match the manifest

**This reverses the direction taken in the first draft of this design**, which proposed renaming
`DashboardRoleService`'s `instructor`→`teacher` and `manager`→`principal` so the resolver would match the
manifest's school-flavored literals. That direction was wrong for a reason external to the defect itself: the
product is being reframed from Scholiq to **Learniq** — a learning system for schools AND companies/training
providers — specifically to STOP being school-only in its domain model. Renaming two already-neutral
identifiers (`instructor`, `manager`) INTO school words, in the same change whose sibling (`rbac-declare-groups`)
is doing the de-schooling work, would have meant renaming them a second time almost immediately.

The discovery that motivated Decision 1 in the first place is unchanged and still correct: no `visibleIf` gate
anywhere in `src/manifest.json` names `instructor` or `manager` — both are dead resolver outputs today. The fix
for that dead-output problem is still "make them consumed", but the CORRECT direction is:

| Option | What it does | Verdict |
|---|---|---|
| Rename resolver outputs to match the manifest's school-flavored literals (first draft) | `instructor`→`teacher`, `manager`→`principal` | **Rejected** — moves the identifier vocabulary AWAY from neutral, directly against the Scholiq→Learniq reframing this change's own sibling change is executing |
| **Correct the manifest's literals to the resolver's neutral vocabulary (chosen)** | `teacher`→`instructor`, `principal`→`administration-manager`, `mentor`→`team-lead`, `parent`→`guardian` in the `visibleIf.in[]` arrays; `instructor` keeps its existing name (only its backing group id changes, per the original Decision 3/item 3 scope); `manager` is renamed to the more specific `administration-manager` per the canonical table (Decision 2 below) | Manifest literals move onto the vocabulary the product needs anyway; zero PHP identifier drift from the pre-fix state for `instructor` |

Net effect: **13 of the 24** role-gated `visibleIf.in[]` arrays in `src/manifest.json` change content (up from
6 in the rejected direction, because correcting the manifest touches every gate that named a school word, not
just the two admin-visibility gates and the four Payments gates). `lib/Service/DashboardRoleService.php`
changes far LESS than the rejected direction would have: `instructor` is untouched as a string (only its
backing group id moves from `scholiq-instructor` to `instructors` — already in scope per the original item 3);
`manager` becomes `administration-manager`; `coordinator`, `team-lead`, and `guardian` are added as new
group-backed roles.

### Decision 2: `manager`→`administration-manager` rename, and three new group-backed roles

Per the canonical vocabulary table, `DashboardRoleService::GROUP_BACKED_ROLES` becomes:

```php
private const GROUP_BACKED_ROLES = [
    'compliance-officer'     => 'compliance-officers',
    'hr'                     => 'hr',
    'administration-manager' => 'administration-managers',
    'team-lead'              => 'team-leads',
    'coordinator'            => 'coordinators',
    'instructor'             => 'instructors',
    'guardian'               => 'guardians',
];
```

(Changed from a plain priority-ordered list of NC-group SUFFIX strings — `'scholiq-' . $role` — to an explicit
role → full unprefixed-group-id map, since the group id no longer derives from the role name by a fixed
prefix rule once `hr` doesn't pluralize and every other id does.)

`manager` is renamed to `administration-manager` (not kept as-is like `instructor`) because the canonical
table disambiguates it from the new `team-lead` role — both "manager" and "mentor" could otherwise plausibly
be read as a manager-shaped word, and the table's job is to make each identifier name exactly one concept.
`coordinator`, `team-lead`, and `guardian` are new — no existing resolver slot to rename into.

**Priority order** (highest first; `admin` is checked separately and first via `isAdmin()`, unconditionally,
before any group lookup — unchanged): `compliance-officer` > `hr` > `administration-manager` > `team-lead` >
`coordinator` > `instructor` > `guardian` > `learner` (fallback). Rationale: oversight/compliance and HR keep
the top two slots unchanged from the pre-fix resolver; `administration-manager` (top operational authority)
outranks `team-lead` (mid-level supervisory) which outranks `coordinator` (specialist/operational) which
outranks `instructor` (front-line staff); `guardian` sits lowest among group-backed roles because it is a
non-staff, external relationship to the school/organisation, not an internal privilege tier. This order only
matters when a single Nextcloud user belongs to more than one of these groups simultaneously (an edge case —
most users belong to at most one) and is a low-stakes implementation choice, not a product decision requiring
further sign-off.

**`resolveViews()`** (dashboard-view tier — Administration/Teaching/My-learning, per the existing "Per-role
group-gated dashboard menu items" requirement already in `dashboard/spec.md`, unaffected structurally by this
change):

```php
if (in_array($role, ['hr', 'compliance-officer'], true) === true) {
    $views[] = 'admin';
}

if (in_array($role, ['administration-manager', 'team-lead', 'coordinator', 'instructor'], true) === true) {
    $views[] = 'teacher';
}

$views[] = 'student';
```

`guardian` deliberately falls into neither `in_array()` check — a guardian gets the base `student`-tier view
only, same as `learner`, because a guardian is not staff and has no claim to the operational "Teaching"
dashboard. This also resolves an ambiguity the rejected direction would have introduced for free: under the
rejected rename, the ROLE STRING `'teacher'` and the dashboard VIEW STRING `'teacher'` (an existing,
independent vocabulary — "Teaching" per the dashboard spec) would have been identical strings for two
different concepts. Keeping the role as `instructor` avoids that collision entirely.

### Decision 3: `learner` stays the unconditional fallback — NOT gated on the `learners` group

The canonical table lists `learner` → `learners`, and `rbac-declare-groups` provisions `learners` as a real
Nextcloud group (likely for OpenRegister-level data RBAC — e.g. an `authorization` block scoping which users
may read certain LearnerProfile-adjacent data). `DashboardRoleService::resolvePrimaryRole()` does **not** add
`learners` to `GROUP_BACKED_ROLES` and does **not** require membership in it to resolve `learner`. The existing
fallback behavior — "no privileged group and not NC-admin ⇒ `learner`" — is preserved exactly, because
`resolveViews()`'s own comment already states the invariant this protects: "Everyone is at least a learner."
Requiring explicit `learners` membership would mean a user in NO group at all (a real, common state — most
pupils/employees are added to `learners` by a provisioning step that could lag their account creation) resolves
to no role at all, which is a strictly worse fail-mode than today's "conservatively becomes a learner." The
`learners` group's existence is real and useful to `rbac-declare-groups`'s data-RBAC concerns; it is simply not
this service's concern.

### Decision 4: Unprefixed group ids, and the dependency is load-bearing — named explicitly

Per the OpenRegister `RbacGroupCollector` design (`openregister/lib/Service/Authorization/RbacGroupCollector.php`
docblock): "Group ids are free-form and deliberately UNPREFIXED — two apps that both declare `behandelaars`
converge on one Nextcloud group by design." **This change's `depends_on: [rbac-declare-groups]` is not
advisory — it is load-bearing for four of the seven group-backed roles.** `instructors`, `hr`, and
`compliance-officers` map to roles the pre-fix resolver already checked (under the `scholiq-*` prefix), so a
delayed provisioning of those three only continues the pre-fix status quo (unreachable, same as today).
**`administration-managers`, `team-leads`, `coordinators`, and `guardians` are the four groups this change
newly relies on `rbac-declare-groups` to declare** — if that change's scope-map declaration omits any of the
four, `IGroupManager::isInGroup()` against it always returns `false`, membership can never be granted, and this
change reproduces the exact defect it exists to fix (a gate that claims delegation but is unreachable) inside
its own fix, for that one role. Per the coordinator's review, `rbac-declare-groups` has been instructed to
declare **all eight** table entries (including `learners`, per Decision 3, for its own purposes), so this is
tracked as a coordinated, confirmed dependency rather than an open question — but the four names above are
recorded here explicitly so the coupling is reviewable, not assumed: **`administration-managers`,
`team-leads`, `coordinators`, `guardians` MUST exist in `rbac-declare-groups`'s merged scope-map declaration
before this change's fix is observable end-to-end.**

### Decision 5: `finance` removed, not made producible (ADR-081) — unchanged from the original review

ADR-081 ("Money and effort have one home each") states a domain app "never books income" and that a fee /
charge is a Pipelinq product and transaction. Scholiq's `PaymentTransactionController` already delegates
initiation and status entirely to OpenConnector (see `payments` capability spec, Requirement "Payment
initiation and status delegate entirely to OpenConnector"). Making `finance` a real, group-backed role in
Scholiq — building a school-local finance staff concept — would run directly against the direction the fleet
has already taken for money: the Payments group is leaving Scholiq to Pipelinq in a later change. Removing
`finance` from the four Payments gates' `in[]` lists (leaving `["admin"]`) is therefore not a placeholder for
"not implemented yet" — it is the correct terminal state until the Payments group itself is removed.

### Decision 6: `guardian` goes back on `BookConferenceSlotsMenu` — visibility now, per-child scoping later (explicit residual)

The first draft of this design removed `parent` from `BookConferenceSlotsMenu` entirely, reasoning that a
parent/guardian's authority is relational (specific to their own child) and the single-scalar `primaryRole`
model can't express "which learner(s)". That reasoning about SCOPING was correct and remains correct — but it
does not justify removing VISIBILITY. Menu visibility is a coarse, group-based gate (can I see this feature at
all); per-object scoping (which slots can THIS guardian actually book) is a separate, finer-grained concern that
belongs to OpenRegister's object-level authorization once the multi-role administration-membership model lands
(explicitly out of scope here, per Non-Goals). Hiding the menu entry to sidestep the scoping question produces
the exact class of defect this whole change exists to close: a feature meant for a role that role can't reach.

**Final gate**: `BookConferenceSlotsMenu`'s `visibleIf.user.primaryRole.in` becomes `["guardian", "learner",
"admin"]`.

**Explicit residual, recorded rather than hidden**: once this change ships, a user resolved as `guardian` can
see and open Book Conference Slots, and — until the later multi-role/relation change lands — **can currently
book against any slot the endpoint exposes, not only slots for their own child(ren)**. This is a real,
accepted gap for the interim period between this change and the later administrations change, not an oversight
discovered after the fact. It is bounded by the same properties any `guardian`-gated feature has today: the
endpoint still requires `guardian`-or-`admin`-or-`learner` group membership (not open to the public or to every
authenticated user), and whatever object-level checks `BookConferenceSlots`'s own controller already performs
(unaffected by this change) still apply. The later change closes the remaining gap by scoping bookable slots to
the guardian's actual child relation(s); this change does not attempt that scoping and must not be read as
having solved it.

## Nextcloud Integration

- Services: `lib/Service/DashboardRoleService.php` — `GROUP_BACKED_ROLES` becomes the associative role→group-id
  map in Decision 2; `resolvePrimaryRole()` iterates it in declaration order; `resolveViews()`'s
  `in_array($role, ['manager', 'instructor'], true)` becomes
  `in_array($role, ['administration-manager', 'team-lead', 'coordinator', 'instructor'], true)`; the class
  docblock's `scholiq-{role}` convention description is corrected to describe the unprefixed group-id map.
- `src/manifest.json` — 13 of the 24 `visibleIf.user.primaryRole.in[]` gates change content (full table in
  `tasks.md` Task 4).
- No controller, route, mapper, or event changes. No new OCP interface usage beyond the existing
  `IGroupManager::isInGroup()` / `isAdmin()` already in use.

## Security Considerations

- **Fail-closed by construction, preserved.** Every group-backed role is still checked via
  `IGroupManager::isInGroup()`; a non-existent or empty group yields `false`, so an under-provisioned group
  (Decision 4) can only ever cause a role to be UNAVAILABLE, never wrongly granted.
- **`admin` is always checked first** in `resolvePrimaryRole()` (unchanged) — no reordering in this change
  weakens that short-circuit, and the new "every gate includes admin" requirement means the short-circuit is
  now actually reachable through every gated menu item, closing the 2-item admin-invisible gap.
- **No self-elevation path is introduced.** `DashboardRoleService` only ever reads NC group membership; a user
  cannot alter their own group membership through anything this change touches.
- **The `guardian` residual (Decision 6) is a scoping gap, not an authentication or authorization-bypass gap.**
  A non-guardian, non-learner, non-admin user still cannot reach the feature at all. The residual is narrower
  than the pre-fix state, which hid the feature from every guardian unconditionally (fail-safe-to-invisible,
  the same defect class this whole change fixes) — trading a total-denial defect for a narrower, explicitly
  tracked over-grant is the correct direction, but the over-grant itself must not be forgotten; it is recorded
  here and MUST be carried into the later administrations change's scope, not silently dropped once this
  change ships.
- **The CI gate is itself a security control**, not a lint nicety: it converts a class of defect that is
  invisible in the running app (a `visibleIf` mismatch fails safe/silent) into a build-time FAIL. Per the spec
  quality bar, this is the reason the gate ships as part of THIS change rather than a follow-up — a defect
  class that hides itself does not get caught by "we'll add a test later."

## CI Gate Extension (hydra, gate 30)

`hydra-gate-effective-manifest-crossref` (gate 30, `scripts/lib/check_manifest_crossref.js` in the
`conduction/hydra-gates` package) already assembles each app's effective manifest and runs several
cross-reference joins JSON Schema cannot express (menu-route→page, action-target, slug-resolution,
deeplink-route, removals-invariant). This is the closest existing gate — extending it (rather than adding a
sixth standalone gate) keeps one place responsible for "manifest names something that doesn't resolve."

Two new check classes, both scoped the same way the existing four are (WARN when the app-side data needed to
check is statically unavailable; FAIL only when it is present and contradicted):

- **`role-resolvable`** — for every `visibleIf.user.primaryRole.in[]` literal (any nesting depth) in the
  assembled manifest, resolve the app's role-resolver's producible-value set and FAIL on any literal not in
  it. The producible-value set is read the same way other hydra gates statically read PHP source (e.g.
  `hydra-gate-orphan-auth`, `hydra-gate-unsafe-auth-resolver`): locate the app's role-resolution service (the
  file whose class provides `runtime.user.primaryRole` — discoverable by the `IInitialState::provideInitialState`
  call site keyed on `'primaryRole'`, mirroring how `hydra-gate-initial-state` already looks for that pattern),
  then regex-extract the literal string values in its `return` statements and any `GROUP_BACKED_ROLES`-shaped
  constant array's keys. An app with no such discoverable resolver (most apps don't gate on `primaryRole` at
  all) WARNs and is skipped, matching gate 30's existing WARN-on-unknowable posture for slug-resolution.
- **`group-declared`** — for every `IGroupManager::isInGroup($uid, '<literal>')` call site (regex-scoped to
  files under `lib/`), resolve the group id against the union `RbacGroupCollector::fromDocument()` would
  produce for the app's own `lib/Settings/*register*.json` (parsed the same way `slug-resolution` already
  parses those files for schema slugs) — a hydra-side reimplementation of the collector's group-extraction
  logic in JS, since the gate cannot invoke live PHP. FAIL when a called group id resolves to neither the
  derived-floor authorization blocks nor the authored scope map. WARN (not FAIL) on a dynamically-constructed
  group name (e.g. `'scholiq-' . $role` — a non-literal argument the regex cannot resolve), so this check only
  ever fires on the literal-string call shape this defect actually took.

Both checks are diff-scoped per ADR-020 the same way the other four are: run when the PR touches
`src/manifest.json`, `src/manifest.d/**`, `lib/Service/**/*.php` (for `role-resolvable`'s resolver discovery),
or `lib/Settings/*register*.json` (for `group-declared`'s declaration lookup).

Fixtures follow the existing pattern in `scripts/test-fixtures/effective-manifest/`: `good/` includes a
resolver + manifest pair that resolves cleanly (one open-modal-shaped WARN tolerated, matching the existing
fixture set's convention); `broken/` adds one seeded defect per check class — a `visibleIf` literal absent
from the resolver's producible set, and an `isInGroup()` call naming an undeclared group id.

## Migration Plan

No database migration, no schema migration. Deployment order:

1. `rbac-declare-groups` merges and deploys first (this change's dependency) — provisions all eight table
   entries as real Nextcloud groups: `learners`, `instructors`, `team-leads`, `coordinators`, `hr`,
   `compliance-officers`, `guardians`, `administration-managers`.
2. This change deploys — `DashboardRoleService` starts checking the newly-provisioned group ids; the manifest
   gates that were effectively admin-only start actually delegating to whoever an admin has added to those
   groups.
3. If step 1 has not yet happened when this change deploys, behavior degrades gracefully: `isInGroup()` on a
   not-yet-existing group returns `false`, so every non-admin role stays unreachable exactly as it was before
   this change (not worse) — deploy order is a sequencing preference for the fix to be OBSERVABLE end-to-end,
   not a hard runtime dependency. Per Decision 4, this degrade-gracefully property covers `instructors`/`hr`/
   `compliance-officers` at no new risk (same as pre-fix); the four newly-relied-upon groups
   (`administration-managers`, `team-leads`, `coordinators`, `guardians`) simply stay at "admin-only in
   practice" (not worse than pre-fix) until `rbac-declare-groups` catches up.

Rollback: revert `lib/Service/DashboardRoleService.php` and the 13 touched `visibleIf` blocks in
`src/manifest.json` independently of `rbac-declare-groups` or the hydra gate change — none of the three parts
of this change depend on each other at runtime for safety (only for the fix to be fully effective).

## Open Questions

- Should `coordinator`/`team-lead` get their own `resolveViews()` dashboard-view tier instead of sharing the
  `teacher`-tier view with `administration-manager`/`instructor` (Decision 2)? Deferred as a product-UX
  question, not a correctness one — sharing the tier is safe (no under- or over-privilege), just possibly not
  the ideal UX. This is the one open question carried forward from the original review; the naming-convention
  and provisioning-dependency questions that were previously open here are both resolved (Decisions 2 and 4).
