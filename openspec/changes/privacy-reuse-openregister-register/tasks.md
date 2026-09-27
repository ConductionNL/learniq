# Tasks: privacy-reuse-openregister-register

Tier: must (MVP). D20.

## 1. Register

- [x] 1.1 Remove `DataSubjectRequest` from `lib/Settings/learniq_register.json` and from the register's schema list; bump `info.version` to 0.25.0 with a changelog sentence.
- [x] 1.2 Remove the three `data-subject-request` seed rows from `lib/Settings/learniq_mock_register.json`.

## 2. Migration

- [x] 2.1 Add `lib/Repair/MigrateDataSubjectRequestsToOpenRegister.php` (mapping and idempotency per design.md).
- [x] 2.2 Register it in `appinfo/info.xml` `post-migration` before `InitializeSettings`; bump the app version so the step runs.

## 3. Pages

- [x] 3.1 Point `DataSubjectRequests` and `DataSubjectRequestDetail` at `data-subject-requests` / `dataSubjectRequest`, lifecycle field `status`; retitle to "Privacy requests".
- [x] 3.2 Repoint `LearniqSettings.vue`'s recent requests list (register, schema, field names).
- [x] 3.3 Rebuild `PrivacyGovernanceDashboard` as a typed `dashboard` page with `endpointSource` tiles; delete the Vue component and its registry entry.

## 4. Copy and l10n

- [x] 4.1 English and Dutch catalogue keys for the new strings; `npm run l10n:build`; lower the schema l10n baseline.

## 5. Tests

- [x] 5.1 `PrivacyGovernanceRegisterTest`: replace the two tests of the retired schema with one asserting it stays gone.
- [x] 5.2 New `PrivacyReuseOpenRegisterManifestTest`: page bindings, typed dashboard, every tile path checked against the real controller payload.
- [x] 5.3 New `MigrateDataSubjectRequestsToOpenRegisterTest`: mapping, idempotency, retry after failure, fresh install, step order.

## 6. Verify and ship

- [x] 6.1 Diff-scoped checks, `composer check:strict`, `npm run lint`, `npm run format`, `npm run check:specs`, hydra gates, once before push.

Documentation: the privacy request pages are the same menu entries with a new title; no screenshot run (no instance for this lane).
