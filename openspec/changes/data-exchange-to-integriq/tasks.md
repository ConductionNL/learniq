# Tasks: data-exchange-to-integriq

Spec: `openspec/changes/data-exchange-to-integriq/specs/data-exchange/spec.md` (`DE`) and
`.../specs/attendance/spec.md` (`AT`).

### Task 1: Register
- **spec_ref**: DE "Persist DataExchangeJob..." (removed), DE "The gate refuses an OSO or SWV file...", AT
- **files**: `lib/Settings/learniq_register.json`, `lib/Settings/learniq_mock_register.json`
- [ ] Remove the four schemas; add ExchangePartnerApproval, TeldatumCheck, DossierReview with lifecycles, authorization and seeds; AttendanceFlag feedback transition; drop the `$ref` on five `dataExchangeJobId` properties; bump versions

### Task 2: Example sets
- **files**: `scripts/example-sets/po.py`, `lib/Settings/profiles/po.json`
- [ ] Drop the three LVS import jobs, keep a reserved uuid slot, regenerate

### Task 3: Asking integriq
- **spec_ref**: DE "Learniq asks integriq to carry an exchange"
- **files**: `lib/Service/IntegriqExchangeClient.php`, `lib/Exception/IntegriqUnavailableException.php`, `lib/Exception/ExchangeRequestRefusedException.php`, `lib/Lifecycle/AttendanceFlagCreationHandler.php`, `lib/Listener/SupportRequestSubmitHandler.php`, `lib/Listener/SchoolAdviesSendToRodHandler.php`, `lib/Controller/ExchangeRequestController.php`
- [ ] Client plus the four callers

### Task 4: The gate
- **spec_ref**: DE gate requirements
- **files**: `lib/Service/ExchangeGateService.php`, `lib/Service/ExchangeDisclosure.php`, `lib/Service/DataExchangePayloadBuilder.php`, `lib/Listener/ExchangeGateListener.php`, `lib/Controller/ExchangeGateController.php`
- [ ] Five conditions, disclosure per mapping, listener, HTTP route

### Task 5: After a job
- **spec_ref**: DE "A succeeded SWV exchange routes its support request", AT
- **files**: `lib/Listener/ExchangeJobConcludedListener.php`, `lib/Lifecycle/AttendanceFlagReportGuard.php`, `lib/Lifecycle/MunicipalityFeedbackGuard.php`, `lib/Listener/MunicipalityFeedbackStampListener.php`, `lib/Lifecycle/OsoDossierReviewGuard.php`, `lib/Controller/PrivacyGovernanceController.php`
- [ ] Concluded listener, guards and controller on the new state

### Task 6: Retire the old code
- **files**: `lib/Listener/DataExchangeRunHandler.php`, `lib/Lifecycle/DataExchangeRunGuard.php`, `lib/Listener/RejectionMappingHandler.php`, `lib/Lifecycle/RejectionResubmitGuard.php`, `lib/Lifecycle/RejectionWaiveGuard.php`, `lib/Lifecycle/Action/RejectionResubmissionAction.php`, `lib/Service/RejectionResubmissionResolver.php`, `lib/Service/ExchangeRejectionContract.php`, `lib/Service/DataExchangeTransformer.php`, `lib/Timetabling/TimetableImportHandler.php`, `lib/Timetabling/TimetableRecordMapper.php`, registrars
- [ ] Delete, unregister, delete their tests

### Task 7: Migration
- **spec_ref**: DE "Existing exchange rows are moved to integriq, or archived"
- **files**: `lib/Repair/MigrateDataExchangeToIntegriq.php`, `appinfo/info.xml`
- [ ] Archive always, migrate when integriq is there, once

### Task 8: Frontend
- **spec_ref**: DE "The Data exchange menu is a read-only status panel..."
- **files**: `src/manifest.d/data-exchange.json`, `src/manifest.d/compliance.json`, `src/manifest.d/dashboard.json`, `src/menu-layout.json`, `src/registry.js`, `src/views/OsoDossierReviewView.vue`, `src/views/ExportRequestView.vue`, `src/views/AdminRoot.vue`
- [ ] Status panel, gate pages, request screen on the new endpoint

### Task 9: Copy and routes
- **files**: `appinfo/routes.php`, `lib/Settings/connections.json`, `l10n/en.json`, `l10n/nl.json`
- [ ] Routes, connection entry, every new string in both catalogues

### Task 10: Tests and specs
- **files**: `tests/Unit/**`, `tests/e2e/**`, the superseded notes in six earlier changes
- [ ] New tests for every new class; register tests follow the move; superseded notes

## Verification

- Diff-scoped while building; once before push `composer check:strict`, `npm run lint`, `npm run format`, `npm run test:l10n`, `npm run check:schema-l10n`, hydra gates.
