---
kind: code
depends_on: [data-exchange-to-integriq, lvs-score-freeze]
---

# Proposal: import-landing-answer

## Summary

Integriq now hands the records of an import job back to the owning app in `ExchangeRecordsReceivedEvent` (ConductionNL/integriq#2251, `openspec/changes/exchange-import-landing/design.md` there) and ends the job `no-owner-answer` when nobody answers. Learniq answers it for `lvs-results`, `oso` and `migration-import`: it lands each record where it belongs and calls `accept()` with the count taken and the rejections.

## Motivation

TRACKER-R2 "ROUND 3 FINAL": "import landing for lvs-results, OSO import, migration-import (no-handler)". Without a listener these jobs end `failed` with `no-owner-answer`.

## Affected Projects

- [ ] Project: `learniq`: new `ExchangeImportLandingListener` and `ExchangeImportLanding`, `CaseListenerRegistrar`, a verbatim test stub of integriq's event.

## Scope

### In Scope

- `lvs-results`: an LvsResult in `imported` with `dataExchangeJobId`, upserted on learner, provider, instrument and moment; a verified or archived result is left as it is (lvs-score-freeze). Rejected: `LVS-MISSING-FIELD` (provider, instrument, moment, learnerId), `LVS-UNKNOWN-PUPIL` (no LearnerProfile with that ncUserId).
- `oso`: an OsoImportDossier in `received`, held for review, once per sending school, ECK iD and received time. Rejected: `OSO-MISSING-FIELD` (sourceSchoolBrin).
- `migration-import`: onto the LearnerProfile by `ncUserId`, filling only empty fields from a whitelist (names, birth date, ECK iD, school, department, address, emergency contacts, medical conditions, allergies); a new pupil gets a new active profile. Identity numbers and roles are never taken. Rejected: `MIGRATION-MISSING-FIELD` (ncUserId).
- Rejections carry codes and field names only. A failing write rejects the record with `IMPORT-WRITE-FAILED`; the listener always answers its own jobs, so a learniq failure never reads as `no-owner-answer`.
- System-level reads and writes (no RBAC, no multitenancy filter): the listener runs inside integriq's background job without a session.

### Out of Scope

- Handing the raw import input to integriq. Learniq's gate answers an import with `allow([])` today, so integriq has no records to map and hand back until learniq supplies its uploaded file or received dossier in that answer. That is a separate change; this one makes learniq ready for the records.
- Catalogue rows for the new error codes (an uncatalogued code shows as the bare code in integriq).

## Approach

Mirror `ExchangeGateListener`: registered by class name, duck-typed getters, own-app and own-target guard.

## New Dependencies

None.

## Impact

Import jobs for the three targets end `succeeded`, `partial` or `failed` by learniq's answer instead of `no-owner-answer`.

## Cross-Project Dependencies

ConductionNL/integriq#2251 (`feat/exchange-import-landing`). Without it the event is never dispatched and nothing changes.

## Risks

### Risk 1: Two deliveries of the same record

**Severity:** Low. **Mitigation:** Upserts by natural key; tests pin that a second delivery writes nothing new.

## Rollback Strategy

Revert the merge commit.
