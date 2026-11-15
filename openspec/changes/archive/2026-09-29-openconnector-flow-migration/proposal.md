---
kind: code
depends_on: []
---

## Why

The fleet is retiring the "classic" OpenConnector ingestion dialect — `Source` /
`Mapping` / `Synchronization` / `Job`, stored as OpenRegister objects and driven by
imperative PHP — in favour of OpenRegister's native Flow engine (ADR-065,
`hydra/openspec/architecture/adr-065-flow-engine-and-canvas.md`). The retirement
decision itself is being recorded as its own hydra ADR, authored in parallel; see
**"Which ADR number" below** — the number is not yet settled. Policy target for the
fleet-wide cutover is **2026-08-31**, but the actual cutover is explicitly gated on
OpenRegister's `flow-sync-decomposition` change
(`openregister/openspec/changes/flow-sync-decomposition/`) landing a real
implementation. That change is itself still a proposal (kind: code, on branch
`feat/flow-sync-decomposition-tasks`, not yet merged) — nothing about it is built
today.

Scholiq has **two live call sites** that depend on the classic dialect:

- `lib/Listener/DataExchangeRunHandler.php` — an `IEventListener` on
  `ObjectTransitionedEvent` for `data-exchange-job` → `running` (every target except
  `timetable-import`). On trigger it loads the job's `DataMappingProfile`, queries
  Scholiq source objects per the job's `scope` (tenant-scoped), builds the outbound
  payload via `DataExchangePayloadBuilder`/`DataExchangeTransformer` (field mapping +
  PII stripping + per-target dossier composition, e.g. the SWV zorgvraag dossier), and
  **delegates the actual wire send to OpenConnector** via
  `POST /apps/openconnector/api/sources/{target}/run` (its own constant is named
  `OPENCONNECTOR_RUN_PATH`). It then records `connectorRunId` + counts +
  validation report and drives the job to `succeed` / `partial` / `fail` via
  `TransitionEngine`, and for the `swv` target, routes the originating
  `SupportRequest` on to `routed-to-swv`.
- `lib/Timetabling/TimetableImportHandler.php` — the same event, filtered to
  `target: timetable-import` (the two handlers bail out of each other's target so
  exactly one owns a given job). This is the reverse direction: a **pull**. It calls
  the same OpenConnector REST shape (`POST
  /apps/openconnector/api/sources/timetable-import/run`) to fetch a generated
  timetable, validates each inbound record (cohortId/title/startsAt/endsAt/externalRef
  required), and idempotently **upserts `Session` objects** keyed by
  `(externalRef, tenant_id)` — a manually created Session (no externalRef) is never
  touched. On success it kicks off `TimetableConflictDetector::scan()` over every
  upserted Session.

Both handlers implement no wire protocol themselves (Edukoppeling / StUF / OSO-XML /
Zermelo / Untis / Xedule all stay in OpenConnector) — they are pure orchestration
around one classic-dialect REST call. Once `flow-sync-decomposition` lands, that call
should become a trigger of a native OpenRegister Flow instead.

### Does `POST /apps/openconnector/api/sources/{name}/run` even exist today? — Critical, verified finding

Both handlers' own docblocks already flag this endpoint as *"Assumption documented in
design... not verified against a live OpenConnector instance."* I checked. On
`openconnector`'s current `origin/development`:

- `appinfo/routes.php` has **no** `sources/{id}/run` (or `{name}/run`) route of any
  kind.
- `lib/Controller/SourcesController.php` has **no** `run()` method — only `test()`,
  `logs()`, `tripCircuitBreaker()`, `resetCircuitBreaker()`.
- The **only** "run" verb that exists in OpenConnector today is on
  **synchronizations**: `POST /api/synchronizations/{id}/run` →
  `synchronizations#run`.

So the endpoint scholiq's two handlers call does not exist in OpenConnector at HEAD.
A real call from either handler today would 404 (non-JSON body →
`callOpenConnector()` logs `"OpenConnector returned non-JSON"` and returns `null` →
the job fails with `"OpenConnector connection '{target}' not found or returned an
error"`). This may be a pre-existing defect independent of the flow migration — either
the endpoint was designed but never built in that shape, or OpenConnector's source/
sync split evolved differently after this code was written (sources became pure
configuration; the run verb lives on synchronizations). This change does **not**
attempt to fix that — per scope, this is planning/staging only — but it is flagged
here because it changes the risk read: this may not be "an old dialect that still
works and will later be retired," it may already be inert in production, discovered
only when a job is actually run against a real OpenConnector instance.

### Will the endpoint survive the migration, or is it retired outright?

Honest answer: **uncertain, and the uncertainty above makes the question partly moot.**
Two separate things could be true:

1. If a `sources/{name}/run` (or equivalent) endpoint is reintroduced as a thin
   trigger — e.g. resolving a `Source`-backed Flow and firing it — then in principle
   scholiq's HTTP call shape might not need to change, only what happens behind it.
2. But `flow-sync-decomposition`'s design targets **synchronizations**, not sources
   (`synchronization-run` is the monolith being decomposed into
   `openconnector.source-paginate` / `change-detect` / `contract-resolve` /
   `contract-write` nodes). Its proposal explicitly keeps `synchronization-run`
   "deprecated but present" until a decomposed flow has demonstrably replaced it — it
   does not mention a `sources/.../run` endpoint at all. The classic dialect's
   `Source` object is closer to "connection config" than "runnable thing" in the
   decomposition's own model.

Given (1) never shipped and (2) doesn't target sources, the most likely outcome is
that scholiq's call site does need to change — from POSTing a classic
`sources/{name}/run` REST path to triggering an OpenRegister Flow directly. But this
is a prediction against an unfinished spec, not a fact; **tasks.md below is
explicitly provisional** on this point and must be re-checked once
`flow-sync-decomposition` actually merges and its API surface is real.

### The manual-trigger primitive already exists — the gap is the flow content, not the trigger

Separately from the sources/synchronizations question: OpenRegister already ships a
real, live manual-trigger primitive today, independent of `flow-sync-decomposition`:
`POST /apps/openregister/api/flows/{id}/run` (route `flow#run`), backed by
`OCA\OpenRegister\Service\Flow\Nodes\TriggerManualNode`. So the *mechanism* to fire a
flow programmatically from another app already exists and is not blocked. What is
blocked is that **no Flow can yet express what a classic `Source` run does** —
pagination, change-detection-by-hash, and idempotent contract-based writes are exactly
the four things `flow-sync-decomposition` proposes decomposing out of the
`synchronization-run` monolith into addressable nodes. Until those nodes (or
equivalents) exist, there is no Flow to point `flows/{id}/run` at that would replicate
either handler's current behaviour, particularly the idempotency `DataExchangeRunHandler`
gets for free today via the classic dialect's contract bookkeeping and
`TimetableImportHandler` gets via its own `(externalRef, tenant_id)` upsert.

### Which ADR number

The brief that kicked off this change said "hydra ADR-091 records this decision." I
checked: `hydra/openspec/architecture/adr-091-external-api-surface-belongs-to-openconnector.md`
exists, but it records a **different** decision (externally-authenticated HTTP
surfaces belong in OpenConnector, not this dialect-retirement question) — it was
renumbered onto 091 from 085 on 2026-08-16 specifically because hydra's ADR numbering
has had repeated collisions (documented in that very file's own "Numbering" section,
which lists three separate live collisions on other numbers). The change that actually
matches this brief is `hydra/openspec/changes/adr-092-openconnector-dialect-retirement/`
— on branch `docs/adr-092-openconnector-dialect-retirement`, currently an **empty
scaffold** (no `proposal.md`/`tasks.md` content yet, only an empty
`specs/architecture/` directory), i.e. genuinely being authored in parallel right now,
as the brief said. Given the 091 slot is already taken by an unrelated ADR, this will
most likely land as **ADR-092**, not 091 — but per that same file's own documented
history, the number could still move again before it merges. Reference it by name
(`adr-092-openconnector-dialect-retirement` / "the OpenConnector dialect retirement
ADR") rather than by number until it lands.

## What Changes

**Nothing executes yet.** This change is scope-and-stage only, per explicit
instruction: no PHP is touched, no endpoint is called differently, no Flow is
authored. It exists so that once `flow-sync-decomposition` lands a real
implementation, the follow-up code change has a pre-audited starting point instead of
starting from a cold read of two 500+/650+ line handler files.

- Documents the exact current behaviour of both call sites (this proposal + tasks.md).
- Records the dependency on `flow-sync-decomposition` and the ADR-92 (provisional
  number) fleet decision explicitly, so this change cannot be picked up and
  implemented before its blocker clears.
- Names the provisional Flow-native trigger shape and flags it as unconfirmed pending
  the decomposition's real API surface.
- Flags the pre-existing "does the classic endpoint even exist" finding for whoever
  next touches this code, independent of the migration.

## Capabilities

### Modified Capabilities

None. The canonical `data-exchange` and `timetabling` specs
(`openspec/specs/data-exchange/spec.md` "Delegate wire protocols to OpenConnector",
`openspec/specs/timetabling/spec.md` "Timetable import delegates the wire protocol to
OpenConnector via DataExchangeJob") are already dialect-agnostic — they say Scholiq
"hands the payload to the OpenConnector source/target configuration" without naming a
specific REST shape. Swapping the classic REST call for a Flow trigger, when it
happens, will satisfy those requirements unchanged; no spec delta is needed for this
staging change.

## Impact

- `lib/Listener/DataExchangeRunHandler.php` — future change: `callOpenConnector()`
  (and its `OPENCONNECTOR_RUN_PATH` constant) becomes a Flow trigger call instead of a
  classic-dialect REST POST. Not touched in this change.
- `lib/Timetabling/TimetableImportHandler.php` — same shape of future change to its
  own `callOpenConnector()`. Not touched in this change.
- No schema, register, route, or test file is touched by this change.

## Cross-Project Dependencies

- **Blocking**: `openregister/openspec/changes/flow-sync-decomposition/` — must land a
  real implementation (decomposed synchronization nodes + iteration construct) before
  any code change here is possible.
- **Reference**: `hydra/openspec/architecture/adr-065-flow-engine-and-canvas.md` (Flow
  engine itself, already accepted) and the in-flight dialect-retirement ADR at
  `hydra/openspec/changes/adr-092-openconnector-dialect-retirement/` (not yet written;
  expected number 092, see "Which ADR number" above).
- **Fleet policy target**: 2026-08-31 for the classic-dialect cutover, per the brief
  that opened this change — not independently verified against a written fleet-wide
  policy document in this pass.

## Rollback Strategy

N/A — this change makes no runtime or schema change. Reverting it removes the staging
document only.
