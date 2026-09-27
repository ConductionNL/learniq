---
kind: code
---

# Proposal: cohort-group-provisioning

## Summary

`Cohort.ncGroupId` is a documented field ("Backing Nextcloud group ID … Null
until the cohort is activated") that nothing ever populates. The one lifecycle
guard that mentions it, `CohortMembershipGuard`, says so explicitly in its own
class docblock: "Full NC group synchronisation (ncGroupId provisioning) is
deferred to a separate event listener or manual admin action." That listener
was never built. This change adds it: a listener on Cohort's `activate`
transition that provisions a real Nextcloud group via `IGroupManager`, adds
the Cohort's teachers and learners as members, writes the real group id back
onto the Cohort, and keeps membership in step as `Enrolment`s activate or are
withdrawn afterwards.

## Motivation

Learniq round-1 defect triage (`learniq-defect-triage.md`, entry 4,
"`Cohort.ncGroupId` is a field nothing ever provisions") found the only other
write site, `RolloverExecutionService::groupName()`, computes a **name
string** for a rollover cohort, never a real Nextcloud group — no
`OCP\IGroupManager` call exists anywhere in `lib/`. `change-plan.md`'s
"Foundational / defects" table lists this as an M-effort fix with a direct
precedent to copy: `CohortTalkMembershipHandler` already does the same
"listen for a Cohort/Enrolment lifecycle event, sync an external membership
list, fail soft" shape for Nextcloud Talk conversations instead of NC groups.

## Affected Projects

- [x] Project: scholiq (app id `learniq`) — one new listener class, its
  registration, and its unit tests.

## Capabilities

- Modified: `school-structure` (adds the requirement that Cohort activation
  provisions and maintains a real Nextcloud group)

## Scope

### In Scope

- A new `CohortGroupProvisioningHandler` (`IEventListener<Event>` on OR's
  `ObjectTransitionedEvent`, mirroring `CohortTalkMembershipHandler`'s
  constructor-injection and fail-soft shape) that:
  1. On `Cohort` `activate` (`planned` → `active`): provisions an NC group
     (`OCP\IGroupManager::createGroup()`, idempotent — skips if
     `Cohort.ncGroupId` is already set), adds every id in `teacherIds` and
     `learnerIds` as a member (resolved via `IUserManager::get()`, skipping
     any id that does not resolve to a real `IUser`, exactly as
     `CohortTalkMembershipHandler::resolveUser()` already does), then writes
     the real group id back onto the Cohort via `ObjectService::saveObject()`.
  2. On `Enrolment` `activate`/`withdraw`: resolves the Enrolment's Cohort; if
     that Cohort's `ncGroupId` is already set (i.e. provisioned), adds or
     removes the Enrolment's `learnerId` from that NC group. No-ops (logged)
     when the Cohort has no `ncGroupId` yet.
- Registration in `CollaborationListenerRegistrar` (the file that already
  registers `CohortTalkMembershipHandler`), immediately after that listener's
  registration.
- Unit tests using `createMock()` doubles (never `addMethods`) for
  `ObjectService`, `IGroupManager`, `IUserManager`, mirroring
  `ExemptionGrantHandlerTest`'s capture-buffer convention.

### Out of Scope

- Reconciling `RolloverExecutionService::groupName()`'s computed name string
  with what this listener actually provisions — the triage names this as a
  residual disagreement (rollover writes a *name*, this listener provisions
  a *group id*); fixing the rollover path is a separate change.
- Any change to `CohortMembershipGuard` (the pre-condition check that a Cohort
  has at least one learner before it may activate) — unchanged, still runs
  first.
- File-sharing or calendar permissioning that would *consume* `ncGroupId` —
  out of scope; this change only ensures the field is populated and kept in
  sync.

## Approach

One new PHP listener class plus one registration line, following the exact
precedent `CohortTalkMembershipHandler` already sets for this register (a
lifecycle-event-driven external-membership-list bridge, ADR-031 legitimate
exception — the group provisioning + membership sync cannot be expressed as a
schema declaration).

## New Dependencies

None — `OCP\IGroupManager` and `OCP\IUserManager` are core Nextcloud services,
already injected elsewhere in this app (`CohortTalkMembershipHandler` already
injects `IUserManager`).

## Impact

`Cohort` schema (`ncGroupId` gains a real writer); `lib/Listener/` gains one
new class; `lib/AppInfo/Registrar/CollaborationListenerRegistrar.php` gains
one registration; `tests/Unit/Listener/` gains one new test file.

## Cross-Project Dependencies

None — self-contained within learniq, using only core Nextcloud group APIs.

## Risks

### Risk 1: A learner or teacher id that never resolves to a real `IUser` is silently skipped

**Severity:** Low — **Mitigation:** mirrors `CohortTalkMembershipHandler`'s
existing `resolveUser()` behaviour exactly (log at debug level, skip, never
throw) — this is the established fail-soft convention in this register for
external-membership bridges, not a new risk this change introduces.

### Risk 2: `RolloverExecutionService`'s computed name and this listener's provisioned id can disagree

**Severity:** Low — **Mitigation:** named explicitly in Out of Scope; the
rollover path already computes a *name string*, never a group id, so there is
no existing behaviour this change could regress — it is a pre-existing
disagreement the triage already flagged, tracked for a separate change.

## Rollback Strategy

Revert the new listener class and its one registration line. `Cohort.ncGroupId`
returns to being unpopulated, exactly as it is today — no schema or data
migration to unwind (the field already exists, nullable, in the schema).

## Open Questions

None.
