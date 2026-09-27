---
kind: code
depends_on: []
---

## Why

`findings.md#6.5` and `#L-new-1` (legal tier MUST) are the cleanest total-absence rows the round-1 competitor
census found for learniq: "no Cito/LVS-specific results schema exists" and zero hits for UWLR anywhere in the
tree. Every PO competitor names this connection directly — `iep`: "de toetsresultaten vanuit het IEP LVS
uitwisselen met je LAS"; `boom-lvs`: "via de UWRL koppeling komen resultaten in het LAS zoals Parnassys of
Esis"; `parnassys`: the DULT link where "toetsresultaten worden automatisch naar ParnasSys verstuurd" from
Cito Leerling in beeld (`lvs-report/round1/documented-columns.md`, `sources.md`). `M3-integrations.md` I8
confirms the same gap from the connection side: `learniq today` is `none (m1#6.5)` against ten LVS-naming
competitor cells.

D3 (`decisions.md`) settles who builds what: learniq declares the `DataExchangeJob` type, the payload
mapping, and the lifecycle gate; the wire adapter (the actual UWLR pull, or a file-drop parser) is
integriq's, tracked separately as `integriq-adapter-lvs-imports` (waved to start the same round per
`change-plan.md`'s dependency note, since its only dependency is this change).

`change-plan.md`'s row for this change is `config`, but the round's own precedent for "job type + payload
mapping + lifecycle gate" — `OsoDossierReviewGuard`, `DataExchangeRunGuard`, `MunicipalityFeedbackGuard` — is
each a small, single-method PHP class referenced declaratively from the schema's
`x-openregister-lifecycle.transitions.*.requires`, and every change that added one of those was itself typed
`kind: code` (`2026-07-13-zorgvraag-swv-tlv-chain`, `2026-07-13-verzuim-report-composer`). ADR-032 defines
`config` as JSON-only (register/manifest/OpenAPI) with integration tests; a lifecycle guard is a PHP class,
however small. This proposal keeps the change-plan's scope (schema + mapping + one gate, no new frontend
surface, no integriq adapter work) but labels it `code` per ADR-032 rather than `config`, to avoid the
mixed-kind anti-pattern the ADR calls out.

`report-card-templates` and `trend-and-export-reporting` (both later in the round) both name this change as
their upstream dependency for real LVS content and the DLE/leerrendement scales — this change's job is only
to make the data exist and land safely, not to render it.

## What Changes

- Add `DataExchangeJob.target = 'lvs-results'` (direction: `import`) — no enum change needed, `target` is
  already a free-form string; extend its description alongside the existing named targets.
- Add a `DataMappingProfile` seed (`target: lvs-results`, `direction: import`, `sourceSchema:
  assessment-result`) mapping the UWLR-carried Cito/IEP/Boom/Dia result shape onto the new `LvsResult`
  schema's fields, following the same `fieldMappings` shape the Zermelo/Untis/Xedule import seeds already
  use for `direction: import` (targetField names the external side, scholiqField names ours).
- Add `LvsResult` (new schema, append-only, mirroring `AssessmentResult`'s append-only posture for finished
  evidence): `provider` (enum `cito | iep | boom | dia`), `instrument` (free-text test/toets name, e.g. "Cito
  Rekenen-Wiskunde M6"), `moment` (the meetmoment, e.g. "M6"/"E3" — LVS suites use these, not calendar
  dates), `takenAt` (the calendar date the test was administered), `rawScore` (nullable number — vaardigheidsscore
  is the normed equivalent so raw score is optional per provider), `vaardigheidsscore` (nullable number, the
  IRT-normed score every one of the four suites publishes), `niveau` (nullable string, provider-specific
  level/percentile band), `referentieniveau` (nullable string, e.g. `1F`/`2F`/`1S` — the PO/VO referentiekader
  band, only populated where the toets maps to one), `dle` (nullable number — didactische leeftijd, the input
  `L-new-1`'s DLE/leerrendement scales need), `learnerId`, `assessmentResultId` (nullable `$ref
  AssessmentResult` — links an LVS import to an in-app assessment attempt when the school also ran the same
  toets as an `Assessment`; null when the LVS result has no learniq-side assessment counterpart, which is the
  common case since Cito/IEP/Boom/Dia toetsen are administered outside learniq's own item bank),
  `dataExchangeJobId` (`$ref DataExchangeJob` — which import produced this row), `tenant_id`.
- Add `x-openregister-lifecycle` to `LvsResult`: `imported` (initial) → `verified` (`requires:
  OCA\Learniq\Lifecycle\LvsResultVerifyGuard`, role-gated to admin/coordinator — an automated UWLR/file-drop
  import is not itself proof the row is trustworthy report-card input; a human confirms it once) → `archived`.
  This is the "inbound lifecycle gate" D3 asks every contract to declare: results land as `imported` and are
  not eligible for report-card/trend consumption (a later change's concern) until a coordinator verifies
  them. Mirrors `AssessmentResult`'s own `submit → graded` shape (a human confirmation step between
  machine-produced data and the record other features trust).
- Add `x-property-rbac.read` to `LvsResult` mirroring `AssessmentResult`'s (`admin` anyOf `learnerId ==
  $userId`) — a learner may read their own normed results, admins read all; no dedicated LVS-coordinator role
  exists in this register (same posture `ExchangeRejection`/`SupportRequest` already document).
- New `lib/Lifecycle/LvsResultVerifyGuard.php`: single-method guard, actor-in-`admin`/`coordinator` check,
  same shape as `RejectionResubmitGuard`/`MunicipalityFeedbackGuard`. No wire protocol, no UWLR client code —
  that is integriq's adapter.

## Impact

- Affected specs: `data-exchange` (MODIFIED: target catalogue description; ADDED: `LvsResult` schema
  requirement, inbound verification gate requirement).
- Affected code: `lib/Settings/learniq_register.json` (new schema, new seed, `DataExchangeJob.target`
  description), new `lib/Lifecycle/LvsResultVerifyGuard.php`, new
  `tests/Unit/Lifecycle/LvsResultVerifyGuardTest.php`, new `tests/Unit/Settings/LvsResultRegisterTest.php`.
- No integriq adapter code — that ships separately as `integriq-adapter-lvs-imports` per D3.
- No new frontend surface in this change (no manifest.json pages) — `report-card-templates` /
  `trend-and-export-reporting` consume `LvsResult` for display later; this change only makes the data
  landable and gated.
