## 1. Audit the two classic-dialect call sites (done this pass)

- [x] 1.1 Confirm `lib/Listener/DataExchangeRunHandler.php` still exists and grep-confirm it is the only
      handler for `data-exchange-job` → `running` on every target except `timetable-import`. Read the full
      file (660 lines). Behaviour: loads `DataMappingProfile` → queries tenant-scoped Scholiq source objects
      per `scope` → builds payload via `DataExchangePayloadBuilder`/`DataExchangeTransformer` → POSTs to
      `OPENCONNECTOR_RUN_PATH = '/apps/openconnector/api/sources/%s/run'` → records
      `connectorRunId`/counts/`validationReport` → drives the job to `succeed`/`partial`/`fail` via
      `TransitionEngine` → for `target: swv`, routes the originating `SupportRequest` to `routed-to-swv`.
- [x] 1.2 Confirm `lib/Timetabling/TimetableImportHandler.php` still exists and grep-confirm it owns
      exactly `target: timetable-import` on the same event (the two handlers bail on each other's target).
      Read the full file (529 lines). Behaviour: the reverse (pull) direction — POSTs the same
      `OPENCONNECTOR_RUN_PATH` shape, validates each inbound record
      (cohortId/title/startsAt/endsAt/externalRef required), idempotently upserts `Session` objects keyed by
      `(externalRef, tenant_id)` (a Session with no externalRef is never touched), then runs
      `TimetableConflictDetector::scan()` over the upserted set on success.
- [x] 1.3 Verify against OpenConnector's actual `appinfo/routes.php` / `SourcesController` (checked both the
      local checkout and `origin/development`) whether `POST /apps/openconnector/api/sources/{name}/run`
      exists. **It does not.** No `sources/.../run` route, no `run()` method on `SourcesController`. The
      only existing "run" verb is `POST /api/synchronizations/{id}/run`. Flagged as a critical,
      independent-of-migration finding in `proposal.md` — **not fixed here**, planning only.
- [x] 1.4 Check whether OpenRegister already exposes a manual Flow-trigger primitive independent of
      `flow-sync-decomposition`. It does: `POST /apps/openregister/api/flows/{id}/run` (route `flow#run`),
      backed by `TriggerManualNode`. The blocker is not the trigger call — it is that no Flow can yet
      express pagination / change-detection / idempotent contract writes, which is exactly what
      `flow-sync-decomposition` proposes decomposing out of `synchronization-run`.

## 2. Identify the provisional Flow-native equivalent (provisional — re-verify once flow-sync-decomposition merges)

- [ ] 2.1 Once `flow-sync-decomposition` lands real nodes, re-read its final `design.md`/spec deltas (not
      just today's `proposal.md`) to confirm whether the decomposed set targets `Synchronization`-shaped
      config only, or also produces something addressable from a `Source`-shaped config the way scholiq's
      `target` field names it today (e.g. `bron-rod`, `oso`, `swv`, `timetable-import`).
- [ ] 2.2 Determine the concrete OpenRegister call `DataExchangeRunHandler::callOpenConnector()` and
      `TimetableImportHandler::callOpenConnector()` should make instead of the classic REST POST. Leading
      candidate, **not confirmed**: `POST /apps/openregister/api/flows/{id}/run` (`flow#run`,
      `TriggerManualNode`) against a Flow that wraps the decomposed
      `openconnector.source-paginate`/`change-detect`/`contract-resolve`/`contract-write` nodes (push
      direction) or an equivalent pull-shaped flow (for `timetable-import`). Requires: (a) a stable way to
      resolve "which Flow corresponds to this DataExchangeJob's `target`" (today that's the classic `Source`
      object's name; the Flow-native equivalent is unknown until 2.1 is answered), and (b) confirmation the
      decomposed nodes' idempotency/contract bookkeeping covers both the SWV push case
      (`DataExchangeRunHandler`) and the externalRef-keyed upsert case (`TimetableImportHandler`) — today
      the latter does its own upsert in PHP after the pull returns, which may or may not still be the right
      split once writes can happen inside the Flow itself.
- [ ] 2.3 Re-verify the "does the classic endpoint exist" finding (task 1.3) is still true immediately before
      starting the actual code change — OpenConnector's routes could change independently of this migration
      before the migration itself starts.

## 3. Blocked — do not start until flow-sync-decomposition provides real primitives

- [ ] 3.1 **BLOCKED on `openregister/openspec/changes/flow-sync-decomposition/` landing a real
      implementation** (as of this pass: proposal + design only, on branch
      `feat/flow-sync-decomposition-tasks`, not merged). No code in `DataExchangeRunHandler.php` or
      `TimetableImportHandler.php` should change before then — there is nothing to point either handler's
      `callOpenConnector()` at yet.
- [ ] 3.2 **BLOCKED on the fleet dialect-retirement ADR landing** (tracked as
      `hydra/openspec/changes/adr-092-openconnector-dialect-retirement/`, currently an empty scaffold with
      no proposal content). Even once the OpenRegister primitives exist, the actual cutover in scholiq
      should wait for that ADR's accepted decision, since it may set a fleet-wide sequencing or compatibility
      requirement (e.g. a transition window where both dialects must work) not yet known.
- [ ] 3.3 When unblocked, split the real implementation into its own change (this one stays scope/staging
      only) covering, at minimum: swap `DataExchangeRunHandler::callOpenConnector()` and
      `TimetableImportHandler::callOpenConnector()` to the confirmed Flow-native trigger; resolve
      target→Flow mapping; preserve the existing `succeed`/`partial`/`fail` outcome semantics and the SWV
      SupportRequest routing side-effect; preserve `TimetableImportHandler`'s idempotent upsert semantics;
      update/extend unit tests (`tests/Unit/Listener/DataExchangeRunHandlerTest.php`,
      `tests/Unit/Timetabling/TimetableImportHandlerTest.php`) accordingly; add the negative test that a bad
      Flow reference / a Flow-side failure still fails the job cleanly (mirrors the ADR-091/092 "a guard
      nobody has watched refuse is untested" lesson — this is a trigger swap, not an auth swap, but the same
      discipline of proving the failure path applies).

## Quality checklist

<!-- Reminders — plain bullets, not tracked checkboxes. -->

- This change makes **no** PHP, schema, route, or test change. `git diff` against `development` for this
  change should touch only files under `openspec/changes/openconnector-flow-migration/`.
- Section 3 tasks stay unchecked until both blockers (flow-sync-decomposition implementation, dialect-
  retirement ADR) are confirmed landed — checking them off prematurely would misrepresent the gate.
- Section 2's Flow-trigger guess is provisional and must be re-validated against the real
  `flow-sync-decomposition` API surface before any code is written against it.
- `openspec validate openconnector-flow-migration --strict` passes.

## Verification

- [x] Both call sites confirmed present, read in full, and behaviour documented accurately (Section 1).
- [ ] Section 2's provisional Flow-trigger identification re-confirmed once `flow-sync-decomposition` merges.
- [ ] Section 3 remains blocked / unchecked until its two named dependencies land.
