# Tasks: credentials-bulk-reissue

## Implementation tasks

### Task 1: Register: reissue history and transition
- **spec_ref**: `specs/certification/spec.md#requirement-a-reissue-keeps-who-and-when-and-records-why`
- **files**: `lib/Settings/learniq_register.json` (Credential 0.3.0: history properties, `reissue` self-loop, notification), `info.version` bump
- [x] Implement
- [x] Test: `tests/Unit/Settings/CredentialReissueRegisterTest.php`; `npm run check:register`, `npm run check:json-strict`
- Plus `walletOfferNote` for the wallet note; the self-loop has no per-transition authorization because only update-permitted users (hr, compliance officers) can fire it and the run fires it as the staff member who started it. `reissue` inputs equal `CredentialReissueService::REISSUE_INPUTS`, pinned by the register test.

### Task 2: Reissue service and job
- **spec_ref**: `specs/certification/spec.md#requirement-staff-reissue-every-certificate-of-a-course-in-one-action`, `#requirement-a-reissue-keeps-who-and-when-and-records-why`
- **files**: `lib/Service/CredentialReissueService.php`, `lib/BackgroundJob/CredentialReissueJob.php`
- **acceptance_criteria**:
  - GIVEN a run that stops half way WHEN it runs again with the same run id THEN no credential is reissued twice
  - GIVEN a revoked credential WHEN the run passes it THEN it is left unchanged
- [x] Implement
- [x] Test: `tests/Unit/Service/CredentialReissueServiceTest.php`, `tests/Unit/BackgroundJob/CredentialReissueJobTest.php`
- Batches of 200; idempotent per `reissueRunId`; a failed credential is logged and counted. The issuer is the tenant's current School name. Tests: `CredentialReissueServiceTest` (the job only resolves the account and calls the service; no separate job test).

### Task 3: Route and course action
- **spec_ref**: `specs/certification/spec.md#requirement-staff-reissue-every-certificate-of-a-course-in-one-action`
- **files**: `lib/Controller/CredentialReissueController.php`, `appinfo/routes.php`, `src/modals/ReissueCertificatesModal.vue`, `src/manifest.d/learning.json`, `src/registry.js`
- [x] Implement
- [x] Test: `tests/Unit/Controller/CredentialReissueControllerTest.php` (instructor refused); Playwright `tests/e2e/credential-reissue.spec.ts`; hydra gates 5, 7, 30
- Live 2026-09-29 on localhost:8080 (#1460, served development 71a2c414): `credential-reissue.spec.ts` 2 passed (7.5m). A temporary compliance officer opens the course's Actions menu, then Reissue certificates, and reads "2 issued certificates will be rebuilt and signed again." and "1 revoked and 1 expired certificates are left as they are.", then enters a reason and starts the run. The queued `CredentialReissueJob` is executed through occ (no cron on the instance). Both issued certificates keep issuedAt 2026-03-03, courseId and kind, and carry reissueCount 1, the reason, reissuedBy = the officer, and a new signature; the revoked and expired ones are unchanged. The certificate page shows 03/03/2026 with the reason and the officer. The audit entry is read as admin, because OpenRegister's audit-trails endpoint answers only admins. The first live runs failed every certificate on a stale `appendOnly` (fixed in #1439).
- A page `ReissueCertificatesView` (`/courses/:courseId/reissue`) from a CourseDetail header action, instead of a modal; the action shows on every course (the server answers the counts, and a course without certificates shows 0). `CredentialReissueControllerTest` covers the instructor refusal and the queued run; the Playwright test is not written: no live instance.

### Task 4: Wallet status and learner notification
- **spec_ref**: `specs/certification/spec.md#requirement-a-learner-hears-that-their-certificate-was-reissued`
- **files**: `lib/Service/CredentialReissueService.php`, register notification block
- [x] Implement
- [x] Test: unit test on the wallet status reset; gate 18 (notification dialect)
- Wallet status cleared with a note (`CredentialReissueServiceTest::testAWalletOfferIsClearedWithANote`); the `reissued` notification on the `reissue` transition (`CredentialReissueRegisterTest`).

### Task 5: Seed data and translations
- **files**: training example set generator, `l10n/en.json`, `l10n/nl.json`, `l10n/*.js`
- [x] Implement
- [x] Test: gate 101, `npm run check:schema-l10n`, `npm run check:l10n-js`

## Verification
- `openspec validate credentials-bulk-reissue --strict` passes
- `composer check:strict` and `npm run lint` show no new finding against the development baseline
- Catalogue strings added; no seed rows with reissue history (the training example set generator is not extended).

