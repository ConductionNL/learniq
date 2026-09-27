---
kind: config
---

# Proposal: session-roster-notifications

## Summary

`SessionChangeNoticeHandler` already materialises `affectedLearnerIds`,
`affectedParentIds` and `changedAt` onto a `Session` object every time it is
cancelled or gets a substitute teacher assigned — exactly the write-side of the
notification recipient shape the `timetabling` capability spec already
describes (`openspec/specs/timetabling/spec.md`, requirement "Cancellation or
substitution notifies affected learners and parents"). But the `Session` schema
in `lib/Settings/learniq_register.json` declares no
`x-openregister-notifications` block at all, so nobody is ever actually
notified: the recipient-resolution machinery the handler was built to feed has
nothing declared to feed it. This change adds the missing declaration —
one JSON block, no PHP change — so the roster-change notification the spec
already promises actually fires.

## Motivation

Learniq round-1 defect triage (`learniq-defect-triage.md`, entry 3, "Session
declares no notifications, so roster-change recipients are computed and never
used") found this by reading `lib/Listener/SessionChangeNoticeHandler.php`'s
own class docblock next to the `Session` schema: the handler's write side is
complete and correct, the schema's declare side was simply never written.
`change-plan.md`'s "Foundational / defects" table lists this as an S-effort
fix. It is the single cheapest defect that recovers a full M1 row on its own
with no dependency: `11.5` ("Roster changes pushed to learners and parents")
cites this exact gap as its only blocker.

## Affected Projects

- [x] Project: scholiq (app id `learniq`) — `lib/Settings/learniq_register.json`
  gains one `x-openregister-notifications` block on `Session`; a new
  `*RegisterTest` asserts its shape.

## Capabilities

- Modified: `timetabling` (extends the existing "Cancellation or substitution
  notifies affected learners and parents" requirement to also cover the
  `substitute-teacher-in-progress` action `SessionChangeNoticeHandler` already
  watches, which the original requirement text did not name)

## Scope

### In Scope

- Add `Session.x-openregister-notifications.rosterChanged` triggered on the
  `cancel`, `substitute-teacher`, and `substitute-teacher-in-progress`
  transitions (the same three actions `SessionChangeNoticeHandler::WATCHED_ACTIONS`
  already reacts to), `channels: ["nc-notification"]`, `recipients: [{kind:
  field, field: affectedLearnerIds}, {kind: field, field: affectedParentIds}]`,
  and an inline `nl`/`en` subject — the exact `kind: field` recipient shape
  already used by `AttendanceFlag.reportDeadlineOverdue`
  (`lib/Settings/learniq_register.json:14167-14193`) and the `transition`
  trigger-with-action-array shape already used by `Credential`'s
  `expired`/`revoked` notifications.
- A `SessionRosterNotificationRegisterTest` (mirroring
  `ReportCardComposerRegisterTest`'s convention) that decodes the register JSON
  and asserts the trigger type, the watched actions, the recipient shape, and
  that the register's `info.version` was bumped.

### Out of Scope

- Any change to `SessionChangeNoticeHandler.php` — it already materialises the
  exact fields this notification's recipients reference; it is unmodified by
  this change.
- Quiet-hours, delivery-suppression, or per-user opt-out logic — per the
  existing `timetabling` spec text, that is OpenRegister dispatcher/preference
  API behaviour, never local logic in this app.
- Defect 7's `$ref` slugification issue — unrelated root cause.

## Approach

A single JSON edit to `lib/Settings/learniq_register.json`'s `Session` schema,
plus a bump of `info.version`, plus one new PHPUnit test file that reads the
register JSON and asserts the new block's shape declaratively (OpenRegister's
own runtime evaluates `x-openregister-notifications` — this repo does not
re-implement that dispatch, only declares the correct shape and verifies it is
declared).

## New Dependencies

None.

## Impact

`lib/Settings/learniq_register.json` (`Session` schema); no PHP, Vue, or
manifest files change. `tests/Unit/Settings/` gains one new test file.

## Cross-Project Dependencies

None — `x-openregister-notifications` is evaluated by OpenRegister core
(already a dependency of every learniq notification), and no other project
consumes `Session`'s schema shape.

## Risks

### Risk 1: A malformed trigger silently never fires (same defect class as this fix corrects)

**Severity:** Medium — **Mitigation:** the new register test asserts the exact
trigger `type`, `action` list, and recipient `field` names against the live
JSON, so a typo in a future edit fails a fast unit test instead of shipping a
second silent notification gap. OpenRegister's own annotation validator
additionally rejects several classes of malformed trigger at schema-load time
(verified for the `calculatedChange` trigger type in defect 2's triage;
`transition`-type trigger validation was not independently re-verified here,
so this is named as a residual risk rather than a closed one).

## Rollback Strategy

Revert the `lib/Settings/learniq_register.json` diff (a pure addition to
`Session`, plus the version bump) and delete the new test file. No PHP,
migration, or data change to unwind.

## Open Questions

None.
