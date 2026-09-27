# Design: rbac-scope-kinds-extension

## Context
`findings.md` row 15.1 pointed at `x-property-rbac` as the register's own-record/role RBAC mechanism. Before extending it,
I verified the claim against OpenRegister's actual `lib/` source (read-only sibling checkout at
`nextcloud-docker-dev/workspace/server/apps-extra/openregister`), the same way `rbac-declare-groups` verified
`x-openregister-authorization`. The result changes this change's approach entirely, so it is recorded here in full.

## The x-property-rbac finding
`grep -rn "x-property-rbac" openregister/lib/` returns **zero matches**. The real, enforced mechanisms are:
- `Schema::getPropertyAuthorization($propertyName)` reads `properties.<name>.authorization` (field-masking, a different
  key and a different purpose — hiding individual FIELDS from certain groups on an otherwise-readable object).
- `PermissionHandler::hasGroupPermission()` reads the schema's own `authorization[action]` array (row/object visibility) —
  entries are a literal group-id string, `{role: "<name>"}` (resolved via `authorization.roles`), `{group, match}`
  (conditional), or a `user:<uid>` / `{user, match}` delegation override. This is the mechanism `rbac-declare-groups`
  actually wired up, and the one this change extends.
- `MagicRbacHandler::applyRbacFilters()` is the SQL-emission mirror of the same grammar for list queries — confirmed by
  `PermissionHandler`'s own docblock: "the single PHP-side conditional-match evaluator used across the RBAC stack... Do
  not introduce a fourth match evaluator here."

`DossierNote` in the CURRENT register carries both: a real `authorization.read: ["instructors","compliance-officers"]`
(this is what actually gates the object) and a decoy `x-property-rbac.read.anyOf` naming `admin`/`mentor`/`coordinator` +
an `authorId` self-match — a DIFFERENT, wider set of roles that is never consulted. `PupilDossierNotesRegisterTest.php`
asserts the decoy's shape and calls it "the confidentiality-floor x-property-rbac boundary," which is not true at
runtime. This is inherited debt (not this change's to fix — flagged, not touched), but it is exactly why this change
does not extend `x-property-rbac`: doing so would add a second layer of fabricated guarantee on top of the first.

## The match grammar, confirmed
`ConditionMatcher::objectMatchesConditions(object, match)` iterates `match` as `{property => value}` pairs (AND across
keys), where `value` is either a literal (equality) or an operator object (`OperatorEvaluator::valueMatchesOperator`).
`resolveDynamicValue()` resolves `$userId`, `$user.<property>` (including `$user.groups`, an array of the caller's
Nextcloud group ids — `resolveUserGroups()`), `$organisation`, and `$now` recursively, including inside operator
operands. `OperatorEvaluator` implements `$eq`/`$ne`/`$in`/`$nin`/`$contains`/`$exists`/`$gt`/`$gte`/`$lt`/`$lte`:
- `$in`: `in_array($objectValue, $resolvedOperand, true)` — "is the object's scalar one of these array members?"
- `$contains`: the documented mirror — "is this value one of the object's array members?" (checks an array-VALUED
  object property for a member), per that operator's own docblock: "which is what a per-object principal list needs and
  what no existing operator could express."

Two scope kinds follow directly, with zero new PHP:
- **own-group**: `match: {<groupField>: {"$in": "$user.groups"}}` — the object's own group-shaped scalar field is
  checked against the caller's group memberships.
- **care-team**: `match: {<listField>: {"$contains": "$userId"}}` — the object's own array-of-user-ids field is checked
  for the caller's uid.

Neither requires a cross-object join (the documented "single-field-on-self" platform gap this register repeatedly flags
for guardian/parent scenarios does not apply here — both conditions read a field ON THE OBJECT ITSELF).

## Decisions

### Decision 1: `Cohort.authorization` becomes explicit, reproducing the cascade exactly
`Cohort` currently has `authorization: null`, relying on the register-level cascade
(`components.registers.learniq.authorization.roles.read-write: ["instructors","hr","compliance-officers","team-leads"]`).
Declaring a schema-level `authorization` block OVERRIDES the cascade entirely (most-specific-wins, no merging, per
`rbac-declare-groups`' own documented mechanism) — so the explicit block must name exactly those four groups for
`read`/`create`/`update`, or `Cohort` would silently lose access for the groups the cascade already granted. The
own-group conditional entry is appended to `read` only.

### Decision 2: `DossierNote`'s new entry is appended, not a replacement
`DossierNote` already has an explicit `authorization` block; the care-team entry is one more array element in the
existing `read` list. `hasGroupPermission()`'s loop grants on the FIRST matching entry — appending only ever widens
who can read, never narrows.

### Decision 3: No change to `x-property-rbac` anywhere
Named explicitly in Out of Scope. Removing/correcting the 40 existing decoy blocks fleet-wide is a separate,
larger cleanup mirroring `rbac-declare-groups`'s own `x-openregister-authorization` removal — flagged as a follow-up,
not built here.

## Declarative-vs-imperative decision (ADR-031)
Both changes are pure declarative JSON (`authorization` blocks, a new property) — no PHP, no guard, no controller. No
imperative exception applies.

## Seed Data (ADR-001)
No new seed objects: `Cohort`'s existing seed entries are unaffected (the new grant is additive and does not require
`ncGroupId` to be set — a `null` `ncGroupId` simply never satisfies the `$in` condition, fail-closed). `DossierNote`'s
new `careTeamUserIds` property defaults to `[]` on every existing and seeded object.

## Risks / Trade-offs
- [Risk] Reproducing the cascade by hand on `Cohort` could drift from the cascade if the cascade itself changes later →
  [Mitigation] the register-shape test asserts the exact grantee list against a hard-coded expectation, so a future
  cascade change that does not also update `Cohort` fails the suite, surfacing the drift immediately.

## Migration Plan
No Nextcloud migration class — declarative OpenRegister schema-register update only. On the next configuration import,
`Cohort`'s explicit authorization block and `DossierNote`'s new property/grant become effective; no existing object
needs a backfill.

## Open Questions
None beyond the one already named in the proposal (confidentiality-tiered branching, deferred).
