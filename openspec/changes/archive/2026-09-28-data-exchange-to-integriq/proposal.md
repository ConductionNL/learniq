---
kind: code
depends_on: []
---

# Proposal: data-exchange-to-integriq

## Summary

Learniq stops running data exchanges itself. `DataExchangeJob`, `DataMappingProfile`,
`ExchangeRejection` and `ExchangeErrorCode` leave the register; their jobs, mappings,
rejections and error codes now live on integriq's own `job`, `mapping` and
`sync_item_dead_letter` schemas (integriq change `learniq-exchange-jobs-native`). Learniq keeps
what is a school's decision: whether a job may run and which records may leave. It answers
integriq's gate for that, keeps its partner approvals, teldatum checks and the parent's review of
an OSO or SWV file as small records of its own, and shows a read-only status panel over
integriq's jobs. A repair step moves existing rows to integriq, or archives them to a file when
integriq is not installed.

## Motivation

Decision D7 (Ruben, 2026-09-27): "Data exchange moves to integriq: jobs, mapping profiles,
rejections and error codes use integriq's own job, mapping, synchronization and log schemas.
Learniq keeps the domain gates (consent, statutory completeness, what may leave) and a read-only
status panel." D25 (same day): built now, in round 3. D7 narrows D3, under which learniq declared
the job type and the mapping itself.

What learniq does today cannot work. `DataExchangeRunHandler` posts to
`/apps/openconnector/api/sources/{target}/run` (`lib/Listener/DataExchangeRunHandler.php:134`),
a route integriq never served, so every job fails; integriq's proposal
`connectors-data-exchange-dispatch` records the same. Meanwhile the eight integriq adapters merged
on 2026-09-26/27 (ROD, Verzuimloket, OSO, UWLR and Edu-V, SWV, LVS, rostering, SLO) wait for a
caller.

Corpus: `learniq-mi/learniq/_round1/compare/decisions.md` D3, D7, D9, D10, D25;
`_round1/compare/change-plan.md` rows 63 (`privacy-governance-surfaces`), 67
(`funding-and-teldatum-checks`), 74 to 78 (the five contract changes), 115 to 122 (the integriq
adapters). The five contract changes merged on 2026-09-27 (`lvs-import-contract`,
`oso-inbound-contract`, `uwlr-eduv-basispoort-contract`, `entree-surfconext-sso-contract`,
`data-mapping-profile-presets`) are the input of this move: their 23 `DataMappingProfile` seeds are
integriq mapping rows now, under the slugs both changes share.

## Affected Projects

- [x] Project: `learniq`: register, mock register, example sets, manifest, menu layout, registry,
  listeners, guards, services, controllers, repair step, connections, l10n, tests, and the
  `data-exchange` spec.
- [ ] Project: `integriq`: consumed, not changed here. `learniq-exchange-jobs-native` ships the
  job tags, the gate event, the three request and conclusion events, the mapping seeds and the
  read model.

## Scope

### In Scope

- Retire `DataExchangeJob`, `DataMappingProfile`, `ExchangeRejection` and `ExchangeErrorCode`
  from the register, the mock register and the primary example set, with their pages, menu
  entries, guards, actions, listeners, services and tests.
- `MigrateDataExchangeToIntegriq`, a repair step: every old job goes to integriq through
  `ExchangeJobRequestedEvent` with its history and its rejections; every customised mapping
  profile through `ExchangeMappingRequestedEvent`. All four schemas' rows are written to
  `data-exchange-archive/retired-data-exchange.json` in app data first, whether integriq is
  installed or not.
- The gate: `ExchangeGateService` decides for a job, `ExchangeGateListener` answers integriq's
  `ExchangeGateRequestedEvent`, and `GET /api/exchange-gates/{jobId}` serves the same decision to
  people. Five conditions: parent review for OSO and SWV (consent), standing partner approval,
  teldatum confirmation, a leerplicht report only once a person took up the flag, and statutory
  completeness of what leaves. What may leave is a per-mapping field list; a statutory target
  without one is refused, as today.
- Three small records for the gates' own state: `ExchangePartnerApproval`, `TeldatumCheck` and
  `DossierReview`, each with its page.
- The flows that queued jobs (leerplicht on an attendance flag, SWV on a submitted support
  request, ROD on a sent school advice, the export request screen) ask integriq instead, through
  `IntegriqExchangeClient`, and fail closed when integriq is absent.
- `ExchangeJobConcludedListener`: a succeeded SWV job still moves its support request to
  `routed-to-swv`.
- The municipality's feedback on a leerplicht report moves from the job to `AttendanceFlag`.
- The Data exchange menu becomes a read-only status panel over integriq's `job` and
  `sync_item_dead_letter` rows owned by learniq, next to the gate pages.

### Out of Scope

- The timetable import. D10 moves it to planninq (lane r3-rostering). `TimetableImportHandler`
  listened for a `DataExchangeJob` and is removed with it; planninq delivers the sessions.
- Import landing for `lvs-results`, the OSO import direction and `migration-import`: integriq
  concludes those jobs `no-handler` until a records hand-back exists. `LvsResult` and
  `OsoImportDossier` stay, filled by hand or by that follow-up.
- Correcting rejections: that is integriq's resubmit and waive now.
- Course package, QTI and learning record import and export: content formats that stay in
  learniq (D7).

## Approach

Learniq talks to integriq only through typed events (ADR-041), guarded by `class_exists()` and
failing closed. The gate state that used to sit as fields on `DataExchangeJob` becomes three
records with declarative lifecycles; the gate reads them. The status panel is declarative: index
pages on integriq's own schemas, filtered to `ownerApp = learniq`, the pattern learniq's
Integrations page already uses. No custom page is added; one (`OsoDossierReviewView`) goes.

## New Dependencies

None. Integriq stays optional: without it, no exchange runs, and every request is refused with a
message saying integriq is needed.

## Impact

About 70 files, most of them deletions. See design.md.

## Cross-Project Dependencies

- Depends on integriq `learniq-exchange-jobs-native` for anything to run. Until it lands, every
  request fails closed with "integriq is not installed or too old".
- Lane r3-rostering (D10) touches the timetabling files; this change deletes
  `TimetableImportHandler` and `TimetableRecordMapper` and leaves `TimetableConflictDetector`.
- Lane r3-access touches `tests/Unit/Register/DeclaredAudienceEnforcedTest.php`; this change
  removes the `ExchangeRejection` line from it and adds the three new schemas.

## Risks

### Risk 1: A school with pending jobs and no integriq
**Severity:** Medium. **Mitigation:** the archive file keeps every row; the repair step says how
many it archived. A statutory report in flight must be sent again once integriq is installed.

### Risk 2: The ROD mapping sends the ECK iD in DUO's BSN field
**Severity:** Medium. **Mitigation:** inherited as learniq wrote it (`bsn-to-pseudonym`): learniq
never releases the BSN. DUO identifies a pupil by BSN, so live ROD traffic needs a privacy decision
in learniq first. It waits on the DUO certificate anyway (D9).

### Risk 3: Two lanes on the same files
**Severity:** Low. **Mitigation:** named in the PR body so the landing orders them.

## Rollback Strategy

Revert the PR. The schemas return, and the rows the repair step left in place are still there:
it copies, it never deletes. Jobs already created in integriq stay there.

## Open Questions

None. Decisions taken without asking are recorded in the PR body.
