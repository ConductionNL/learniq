# Design: session-roster-notifications

## Context

`SessionChangeNoticeHandler` (an `IEventListener` on OpenRegister's
`ObjectTransitionedEvent`) already resolves and writes `affectedLearnerIds`,
`affectedParentIds`, and `changedAt` onto a `Session` for the `cancel`,
`substitute-teacher`, and `substitute-teacher-in-progress` transitions. The
`Session` schema in `lib/Settings/learniq_register.json` declares no
`x-openregister-notifications` block, so OpenRegister's notification
dispatcher has nothing to evaluate — the handler's output is written and never
read by anything.

## Goals / Non-Goals

**Goals:**
- Declare the `x-openregister-notifications` block the `timetabling` spec
  already promises, using the existing `kind: field` recipient shape and
  `transition` trigger type this register already uses elsewhere.
- Cover all three actions the handler actually watches, not just the two the
  original spec text named.

**Non-Goals:**
- Any change to how recipients are resolved (that's
  `SessionChangeNoticeHandler`'s job, already correct) or how OpenRegister
  dispatches a declared notification (platform behaviour, out of this app's
  control).

## Declarative-vs-imperative decision (ADR-031)

This change is a pure declarative fix: the behaviour (notify on a Session
roster-affecting transition) belongs entirely under
`x-openregister-notifications` in the schema register, per ADR-031's default
path. No new `lib/Service/*Service.php` class is introduced — the imperative
half of this feature (materialising the recipient fields) already exists in
`SessionChangeNoticeHandler` and stays untouched, since ADR-031 treats
"resolve a two-hop cross-schema join into a flat recipient field" as a
legitimate PHP exception (the same class as `ConferenceRound.invitedLearnerIds`),
while the notification *declaration* itself has no such exception — it is
always declarative.

## Decisions

### Decision 1: `transition` trigger with an `action` array, not three separate rules

`AnnotationNotificationDispatcher::matchesTrigger()`
(`openregister/lib/Service/Notification/AnnotationNotificationDispatcher.php:1990-2000`)
accepts either a scalar `action` or an array of actions for a `transition`
trigger (`in_array($actual, $expected, true)` when `$expected` is an array).
`Credential`'s `expired`/`revoked` notifications each use a single scalar
action; there is no existing three-action precedent in this register, but the
dispatcher code path is unconditional on array size. One rule with
`"action": ["cancel", "substitute-teacher", "substitute-teacher-in-progress"]`
is chosen over three separate notification keys (e.g. `cancelled`,
`substituted`, `substitutedInProgress`) because all three produce an
identical recipient set and an identical subject — three copies of the same
rule would only add drift risk (an edit to one copy's subject text not
mirrored to the other two).

**Alternative considered:** one notification key per action, matching
`Credential`'s single-action-per-key style exactly. Rejected: `Credential`'s
three notifications (`expiringSoon`, `expired`, `revoked`) have three
genuinely different subjects and recipient-relevant semantics; Session's three
actions share one meaning ("something about your roster changed") and one
subject, so a single multi-action rule is the more faithful declaration, not
a stylistic shortcut.

### Decision 2: The register test asserts shape, not runtime dispatch

Mirroring `ReportCardComposerRegisterTest`'s established scope note: OpenRegister
core evaluates `x-openregister-notifications` at runtime and does not live in
this repository. `SessionRosterNotificationRegisterTest` asserts the declared
trigger type, the action list, the recipient field names, and the version
bump — not that a notification is actually delivered end-to-end (that is
OpenRegister's own test surface, and this app's e2e/nightly matrix for a
Session-cancellation user journey, unaffected by this change).

## Risks / Trade-offs

- [Risk] A future edit to `SessionChangeNoticeHandler::WATCHED_ACTIONS` adds a
  fourth action without updating this notification's `action` array, silently
  reintroducing the same defect class for the new action. → Mitigation: the
  register test's action-list assertion is written as an explicit equality
  check against the three known actions, so a mismatch between the handler's
  constant and the register's `action` array would need catching by a
  cross-file test to be airtight; that stronger guard is out of scope for
  this S-effort declarative fix and is named here rather than silently
  deferred.

## Migration Plan

None — a JSON-only register edit that OpenRegister reads at request time; no
database migration, no deployment step beyond the normal app release.

## Open Questions

None.
