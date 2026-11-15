# Design: cohort-group-provisioning

## Context

`Cohort.ncGroupId` is declared but never written except a name-string
computation in `RolloverExecutionService` for rollover-created cohorts.
`CohortMembershipGuard`'s own docblock defers real provisioning to "a separate
event listener or manual admin action." `CohortTalkMembershipHandler` already
solves the identical shape of problem — sync an external membership list
(Talk conversation participants) from Cohort/Enrolment lifecycle events — for
Talk instead of NC groups.

## Goals / Non-Goals

**Goals:**
- Provision a real Nextcloud group on Cohort activation and write its id back.
- Keep membership in step with Enrolment activate/withdraw afterwards.

**Non-Goals:**
- Reconciling `RolloverExecutionService`'s computed name with the provisioned
  group id (named as a residual disagreement in the proposal, separate fix).
- Anything that *consumes* `ncGroupId` (file sharing, calendar permissioning).

## Declarative-vs-imperative decision (ADR-031)

This is a legitimate imperative exception: provisioning and maintaining an
external Nextcloud group from two different objects' lifecycle transitions is
a cross-object, cross-API-surface bridge with no declarative equivalent in
this register's dialect (`x-openregister-notifications` sends notifications,
not group-membership calls; there is no `x-openregister-group-sync` key).
`CohortTalkMembershipHandler` is the direct, already-accepted precedent for
exactly this class of exception in this register.

## Decisions

### Decision 1: One listener, two schemas, not two listeners

`CohortGroupProvisioningHandler` listens on `ObjectTransitionedEvent` and
branches internally on `getSchema()` (`cohort` vs `enrolment`), mirroring
`CohortTalkMembershipHandler`'s own shape (that class also only reacts to
`enrolment` events, but is registered once for the event class, not per
schema — NC's event dispatch is by event class, and schema filtering is the
listener's own first check). One class keeps the "provision" and "sync" halves
of one feature next to each other instead of splitting them across two
registrations that must always agree on when a Cohort counts as "provisioned."

### Decision 2: Idempotent provisioning guarded by `ncGroupId`, not a second lifecycle state

`Cohort.activate` is a `planned` → `active` one-shot transition per the
existing `x-openregister-lifecycle` declaration, so under normal operation
this listener only ever sees `activate` once per Cohort. The `ncGroupId`
already-set check is defence-in-depth for any other path that might
re-dispatch the same transition event (a retry, a future `reactivate`-shaped
addition), not a response to an observed bug — the same idempotency posture
`AttendanceFlagCreationHandler::flagAlreadyExists()` takes for its own
crossing-event handler.

### Decision 3: Group id naming — deterministic, not the rollover name string

The provisioned NC group id is `learniq-cohort-{cohortId}` (the Cohort's own
UUID), deterministic and collision-free by construction, deliberately
different from `RolloverExecutionService::groupName()`'s human-readable
computed name (which was never a real group id to begin with). Reusing that
computed string as a real NC group id was considered and rejected: NC group
ids must be stable and are shown to admins verbatim in the Users settings
page, and a human-readable rollover name could collide across cohorts or
academic years in a way a UUID-derived id cannot.

## Risks / Trade-offs

- [Risk] `RolloverExecutionService`'s computed name and this listener's real
  group id now visibly disagree (one is a UUID-derived id, the other a
  readable name) → Mitigation: named explicitly as out of scope; no existing
  behaviour regresses since the rollover path never wrote a real group id
  before this change either.

## Migration Plan

None — a new listener class plus one registration line; no schema or database
migration. Existing Cohorts that are already `active` will not be
retroactively provisioned by this change (the listener only reacts to the
`activate` transition firing); a one-off backfill for already-active cohorts
is a follow-up, not part of this fix, and is named here so it is not silently
assumed to be covered.

## Open Questions

None.
