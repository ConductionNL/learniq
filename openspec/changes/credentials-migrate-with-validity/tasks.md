# Tasks: bring existing certificates and their validity dates over from the old system

## 1. Register

- [x] 1.1 `Credential`: add `legacyNumber`, `legacyIssuer`, `migratedAt`, `migratedBy`, `migrationRunId`; make `signature` and `openbadges3Payload` required only when `source` is not `migrated` (an `if/then` in the schema). Bump the register version. Verify: `npm run check:register`; a migrated payload validates with Opis.

## 2. Service and route

- [x] 2.1 `lib/Service/CredentialMigrationService.php`: match learner by id or email, course by code or regulation by slug, dedupe on learner, course and old number; return per-row results; write only on commit. Verify: PHPUnit for clean, unknown learner, unknown course, duplicate, no end date.
- [x] 2.2 `POST /api/credentials/migrate` with `dryRun`, for hr and compliance-officers (action matrix entry `credential.migrate`). Verify: controller test, including a refused learner role.
- [x] 2.3 Make `CredentialIssuanceHandler` and `CredentialSigningService` skip `source: migrated`. Verify: PHPUnit on the real event. — `CredentialSigningService::check()`/`sign()` refuse a `source: migrated` credential (`isMigrated()`), and the bulk reissue counts one as skipped instead of failed. The handler acts on enrolment completions only and issues `source: auto`, so a migrated certificate's own transitions already pass it by; `CredentialIssuanceHandlerTest` pins that on the real `ObjectTransitionedEvent`.

## 3. Verification page

- [x] 3.1 `CredentialVerifyController`: return the migration facts and no signature check for a migrated row. Verify: PHPUnit.

## 4. Screen

- [x] 4.1 Certificates page: "Certificaten overnemen" upload, preview table, "Overnemen". Reuse the external-training upload's parser. Verify: `npm run check:manifest`, `npm run check:l10n`. — a "Take over certificates" header action on the Credentials index opens `CredentialMigrationDialog`: file, dry-run table with a status and reason per row, "Take over" for the ready rows. The rows come from the external-training upload's `parseCsv` (`src/utils/credentialMigrationUpload.js`, `node --test`). `check:manifest` and `check:l10n` pass; `check:l10n-js` is already stale on development.

## 5. Close out

- [ ] 5.1 Live check: upload a 20-row fixture twice; coverage moves once; one expiring row signs up for its refresher.
- [ ] 5.2 Set row `cred-migrate-with-validity` to built and archive this change.
