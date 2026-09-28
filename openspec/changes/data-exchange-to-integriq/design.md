# Design: data-exchange-to-integriq

Kind: code. Read at learniq `development` 0171a896 and integriq PR #2220
(`learniq-exchange-jobs-native`), whose `contract.md` is the interface.

## What leaves learniq

| Piece | Why it goes | Where it lives now |
|---|---|---|
| `DataExchangeJob` schema, pages, menu entries | the job is an integriq `job` row (D7) | integriq, `ownerApp: learniq` |
| `DataMappingProfile` schema, pages, 23 seeds | mappings are integriq `mapping` rows | integriq seeds `learniq-*` |
| `ExchangeRejection` schema, pages, correction guards and action | rejections are integriq dead letters | integriq `sync_item_dead_letter` |
| `ExchangeErrorCode` schema, pages, 6 seeds | a code table per target | integriq `learniq-exchange-error-codes-*` |
| `DataExchangeRunHandler` | integriq runs the job | `ExchangeJobRunner` |
| `DataExchangeRunGuard` | its three conditions are the gate | `ExchangeGateService` |
| `RejectionMappingHandler`, `RejectionResubmitGuard`, `RejectionWaiveGuard`, `RejectionResubmissionAction`, `RejectionResubmissionResolver`, `ExchangeRejectionContract` | the correction loop | integriq resubmit and waive |
| `DataExchangeTransformer` | named transforms were the mapping | integriq mapping rules |
| `TimetableImportHandler`, `TimetableRecordMapper` | listened for a `DataExchangeJob`; D10 moves the timetable to planninq | planninq (lane r3-rostering) |
| `OsoDossierReviewView` custom page | a parent approves a `DossierReview` on its declarative detail page | `DossierReviewDetail` |

## What learniq keeps

### D1. The gate

`ExchangeGateService::evaluate(jobId, target, direction, ownerRef, scope, withRecords)` returns
`{decision, code, reason, records}`. It checks, in this order, and the first refusal wins:

| # | Condition | Applies to | Refusal code |
|---|---|---|---|
| 1 | a person took up the attendance flag (`in-handling` or later) | `leerplicht` | `flag-not-in-handling` |
| 2 | the parent approved the file (`DossierReview` for this job, `approved`) | `oso`, `swv` export | `parent-review-pending` / `parent-review-rejected` |
| 3 | the target's partner link is approved, when the target has one | any target with an `ExchangePartnerApproval` row | `partner-approval-missing` |
| 4 | the teldatum count is confirmed (`TeldatumCheck` for the date, `confirmed`) | a job whose scope names `teldatumDate` | `teldatum-unconfirmed` |
| 5 | what may leave is defined, and every record carries the statutory fields | statutory targets | `disclosure-undefined` / `statutory-incomplete` |

Condition 1 replaces the `pending-review` state the leerplicht job was created in: the human in
the loop is the person who starts handling the flag. Condition 3 is opt-in per target, as
`requiresPartnerApproval` was: a target with no approval row is not blocked. Condition 4 is
opt-in per job, as `requiresTeldatumCheck` was.

What may leave (D2) is composed only when every other condition passed, so a refused job never
reads a pupil record.

`ExchangeGateListener` answers `OCA\Integriq\Event\ExchangeGateRequestedEvent` for
`ownerApp: learniq` only. It is registered by class name, so it costs nothing without integriq.
It reads the event through its getters after `method_exists()` checks, because learniq does not
depend on integriq's classes.

`GET /api/exchange-gates/{jobId}` (`ExchangeGateController`) reads the integriq `job` row through
OpenRegister, answers 404 when it is not learniq's, evaluates without composing records, and
returns `{jobId, decision, code, reason, checkedAt}`. Staff only: admin, administration
managers, compliance officers, coordinators.

### D2. What may leave

`ExchangeDisclosure` holds, per integriq mapping slug, the learniq fields that mapping reads
(taken from the 23 former `DataMappingProfile` seeds). `DataExchangePayloadBuilder` becomes the
record composer: it reads the scope's objects, keeps only the disclosed fields, runs the
leerplicht and SWV file composers as before, and returns `{recordId, sourceKind, data}`. It never
reads `bsnEncrypted`, `bsnHash` or `email`.

A statutory target (`bron-rod`, `oso`, `swv`) with no mapping, or a mapping learniq has no field
list for, is refused `disclosure-undefined`: the same fail-closed rule the builder's
`MANDATORY_PROFILE_TARGETS` enforced. Any other target without a mapping gets the PII-stripped
pass-through, as before.

Statutory completeness: `bron-rod` records need `eckId`, `birthDate`, `schoolId`; `leerplicht`
records need `learnerId`, `windowStart`, `windowEnd`; `oso` records need `eckId`. A job with an
incomplete record is refused, naming the missing fields and up to five record references, never
values.

### D3. Three records for the gates' own state

| Schema | Holds | Lifecycle | Who writes |
|---|---|---|---|
| `ExchangePartnerApproval` | a standing partner link for a target: partner, shared fields | `pending` → `approved` / `rejected`, `reopen` | compliance officers, administration managers |
| `TeldatumCheck` | the confirmed pupil count for a teldatum and target | `pending` → `confirmed`, `reopen` | compliance officers, administration managers |
| `DossierReview` | the parent's decision on one OSO or SWV file, keyed by the integriq job | `pending` → `approved` / `rejected` | guardians, through `OsoDossierReviewGuard` (a parent of the learner only) |

Every decision stamps the actor and the time with `StampTransitionActorAction`. `DossierReview`
carries the `scholiq-data-exchange` processing activity that `DataExchangeJob` carried: it is the
learniq row about a pupil's exchange.

### D4. Asking integriq

`IntegriqExchangeClient::requestJob()` dispatches `OCA\Integriq\Event\ExchangeJobRequestedEvent`
when the class exists and integriq is enabled, and returns the job id or throws
`IntegriqUnavailableException` / `ExchangeRequestRefusedException` with the refusal. Its callers
keep their existing failure posture:

| Caller | Target | Mapping | On failure |
|---|---|---|---|
| `AttendanceFlagCreationHandler` | `leerplicht` | `learniq-leerplicht-export-melding` | the flag is saved without a job id, as when the old save failed |
| `SupportRequestSubmitHandler` | `swv` | `learniq-swv-export-zorgvraag` | logged; creates the pending `DossierReview` only when a job exists |
| `SchoolAdviesSendToRodHandler` | `bron-rod`, `berichtsoort: schooladvies` | none | logged; the gate refuses it `disclosure-undefined`, as the builder did |
| `ExchangeRequestController` (export request screen) | chosen by the user | chosen | 409 with the refusal, 503 without integriq |

The job's `ownerRef` is `<schema>/<uuid>` of the row that caused it, and its scope carries
`schema`, `filters` and `recordIds`.

### D5. What happens after a job

`ExchangeJobConcludedListener` (`OCA\Integriq\Event\ExchangeJobConcludedEvent`, learniq only):
a succeeded `swv` job moves its support request to `routed-to-swv`, which
`DataExchangeRunHandler::routeSupportRequestToSwv()` did. `AttendanceFlagReportGuard` reads the
integriq job's `exchangeStatus` instead of the old job's lifecycle.

The municipality's feedback on a leerplicht report moves to `AttendanceFlag.municipalityFeedback`,
written by a `recordMunicipalityFeedback` self-loop on `reported` with the same guard and stamp
listener.

### D6. The status panel

Declarative, like the Integrations page (`connection-registry.json`, `register: integriq`):

- `ExchangeJobs`: index over `integriq/job` filtered `ownerApp: learniq`, read-only.
- `ExchangeJobDetail`: detail over `integriq/job` with a data widget and the job's rejections.
- `ExchangeRejections`: index over `integriq/sync_item_dead_letter` filtered `ownerApp: learniq`.
- The gate pages: `ExchangePartnerApprovals`, `TeldatumChecks`, `DossierReviews` with details.

Each integriq page declares `requiresApp: integriq`. `job` and `sync_item_dead_letter` are
readable by every account in integriq, and neither holds personal data (integriq design D3). The
menu stays visible to admins and administration managers only.

### D7. The migration

`MigrateDataExchangeToIntegriq` (repair step, post-migration, runs once, marked in app config):

1. Reads every row of the four retired schemas (paged, bounded, `_rbac: false`).
2. Writes them to `data-exchange-archive/retired-data-exchange.json` in app data. Always.
3. When integriq is enabled and its event class exists: each job goes through
   `ExchangeJobRequestedEvent` with `history` (`legacyId`, status, timestamps, result, error) and
   its rejections (status, code, field names, owner reference, waive reason, deadline). Each
   mapping profile whose name is not one of the 23 seeds goes through
   `ExchangeMappingRequestedEvent` as `learniq-custom-<slug of its name>`. Error codes are archived
   only: integriq's catalogues are seeds.
4. Old rows are not deleted. The schemas are gone from the register, so nothing reads them.

## Declarative versus imperative

| Behaviour | Path | Why |
|---|---|---|
| Partner approval, teldatum check, parent review states | declarative lifecycles | plain state machines |
| Stamping who decided | `StampTransitionActorAction` | the register's existing action |
| Parent-only approval | lifecycle guard | a guard is the declarative hook for a per-actor rule |
| The gate | imperative listener and service | a cross-app command (ADR-041 exception) |
| SWV routing after a job | imperative listener | reacts to another app's event |
| Status panel | declarative pages | no custom view |

## Seed Data

One row each (gate 101), chosen so no seed blocks a real exchange:

- `ExchangePartnerApproval`: target `hr` (no handler), partner "HR-systeem (voorbeeld)", status
  `pending`, shared fields `familyName`, `givenName`.
- `TeldatumCheck`: teldatum 2026-10-01, target `bron-rod`, status `pending`, pupil count 212.
- `DossierReview`: target `oso`, a nil job id, learner `leerling-demo`, status `pending`.

The primary example set loses its three LVS import jobs; its LVS results keep a null
`dataExchangeJobId`. The generator keeps a reserved slot for the retired bucket so every later
uuid stays the same.

## Risks

See the proposal. One more: the five contract changes merged on 2026-09-27 still describe
`DataMappingProfile` seeds in their deltas. Each gets a superseded note pointing here, so an
archive does not bring them back.
