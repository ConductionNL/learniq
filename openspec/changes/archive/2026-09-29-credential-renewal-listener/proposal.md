---
kind: code
---

# Proposal: credential-renewal-listener

## Summary

`Credential`'s `renewalEnrolmentId` field documents a write path ("Written
back by OR batch") that was never built — no controller, listener, or
background job anywhere in `lib/` creates a renewal `Enrolment` or writes this
field, even though the four expiry-adjacent notifications (`issuedToLearner`,
`expiringSoon`, `expired`, `revoked`) are all real and correctly wired. This
change adds the missing piece: a listener on `Credential`'s `expire`
transition that creates a new `Enrolment` for the same learner/course the
expiring credential attests, then writes `renewalEnrolmentId` back onto the
`Credential`.

## Motivation

Learniq round-1 defect triage (`learniq-defect-triage.md`, entry 5,
"`Credential.renewalEnrolmentId` is never written — expiry alerts fire, auto
re-enrolment does not exist") found the schema names a mechanism, "OR batch,"
that does not exist in this codebase. The existing `certification` capability
spec already requires this behaviour outright: "Requirement: Auto-enrol on
renewal or content-version change" — "The system MUST auto-enrol learners in
renewal or delta modules when triggered by expiry or content-version change."
This change closes the **expiry** half of that requirement; the
**content-version-change** half (detecting a Course content-version bump and
cross-referencing which credential-holders need a delta module) is a
materially different, larger feature and is explicitly out of scope here (see
Scope).

## Affected Projects

- [x] Project: scholiq (app id `learniq`) — one new listener class, its
  registration, and its unit tests.

## Capabilities

- Modified: `certification` (closes the expiry-triggered half of "Auto-enrol
  on renewal or content-version change")

## Scope

### In Scope

- A new `CredentialRenewalListener` (`IEventListener<Event>` on OR's
  `ObjectTransitionedEvent`, mirroring `ExemptionGrantHandler`'s
  cross-object-create shape) that, on `Credential`'s `expire` transition
  (`issued` → `expired`), creates a new `Enrolment` (`learnerId`/`courseId`
  copied from the expiring `Credential`, `regulationSlug` carried over,
  `source: credential-renewal`, `mandatory: true`) then writes the new
  Enrolment's id back onto `Credential.renewalEnrolmentId` via
  `ObjectService::saveObject()`.
- Adding `credential-renewal` to `Enrolment.source`'s enum (the register's own
  established convention: `admission` and `subject-choice` were each added to
  this same enum, with a docstring note, when their respective bridges were
  built).
- Registration in `SchedulingListenerRegistrar` (the file that already
  registers the intake/enrolment-adjacent bridges).
- Unit tests using `createMock()` doubles (never `addMethods`), mirroring
  `ExemptionGrantHandlerTest`'s capture-buffer convention.

### Out of Scope

- The content-version-change trigger half of the "Auto-enrol on renewal or
  content-version change" requirement — detecting that a `Course` received a
  new content version and cross-referencing every learner already holding a
  `Credential` for the old version is a distinct, larger feature (it needs a
  content-version field on `Course` that does not exist today, and a
  fan-out across every affected credential, not a single-object transition
  listener). Left as a named, explicit gap, not silently assumed covered.
- Any change to the four existing `Credential` notifications
  (`issuedToLearner`/`expiringSoon`/`expired`/`revoked`) — all already
  correctly wired, unchanged by this fix.
- Reconciling what happens if a learner already holds an active Enrolment for
  the same course when their credential expires (e.g. they are already
  mid-retake) — this change always creates a new Enrolment; deduplicating
  against an existing one is a follow-up, named here rather than silently
  assumed handled.

## Approach

One new PHP listener class, one enum addition, one registration line —
directly following `ExemptionGrantHandler`'s already-accepted precedent for
"lifecycle transition on schema A creates and links an object on schema B."

## New Dependencies

None.

## Impact

`Credential` schema (`renewalEnrolmentId` gains a real writer); `Enrolment`
schema (`source` enum gains one value); `lib/Listener/` gains one new class;
`lib/AppInfo/Registrar/SchedulingListenerRegistrar.php` gains one
registration; `tests/Unit/Listener/` gains one new test file.

## Cross-Project Dependencies

None — self-contained within learniq.

## Risks

### Risk 1: A renewal Enrolment is created even when the learner already holds one for the same course

**Severity:** Low — **Mitigation:** named explicitly in Out of Scope as a
follow-up; today's behaviour (no renewal Enrolment at all) is strictly worse,
so this change is a net improvement even before that follow-up lands. A
duplicate-detection guard would mirror `AttendanceFlagCreationHandler::
flagAlreadyExists()`'s idempotency shape if built.

## Rollback Strategy

Revert the new listener class, its registration, and the `Enrolment.source`
enum addition. `Credential.renewalEnrolmentId` returns to being unpopulated,
exactly as it is today.

## Open Questions

None.
