# Tasks: credentials-bulk-reissue

## Implementation tasks

### Task 1: Register: reissue history and transition
- **spec_ref**: `specs/certification/spec.md#requirement-a-reissue-keeps-who-and-when-and-records-why`
- **files**: `lib/Settings/learniq_register.json` (Credential 0.3.0: history properties, `reissue` self-loop, notification), `info.version` bump
- [ ] Implement
- [ ] Test: `tests/Unit/Settings/CredentialReissueRegisterTest.php`; `npm run check:register`, `npm run check:json-strict`

### Task 2: Reissue service and job
- **spec_ref**: `specs/certification/spec.md#requirement-staff-reissue-every-certificate-of-a-course-in-one-action`, `#requirement-a-reissue-keeps-who-and-when-and-records-why`
- **files**: `lib/Service/CredentialReissueService.php`, `lib/BackgroundJob/CredentialReissueJob.php`
- **acceptance_criteria**:
  - GIVEN a run that stops half way WHEN it runs again with the same run id THEN no credential is reissued twice
  - GIVEN a revoked credential WHEN the run passes it THEN it is left unchanged
- [ ] Implement
- [ ] Test: `tests/Unit/Service/CredentialReissueServiceTest.php`, `tests/Unit/BackgroundJob/CredentialReissueJobTest.php`

### Task 3: Route and course action
- **spec_ref**: `specs/certification/spec.md#requirement-staff-reissue-every-certificate-of-a-course-in-one-action`
- **files**: `lib/Controller/CredentialReissueController.php`, `appinfo/routes.php`, `src/modals/ReissueCertificatesModal.vue`, `src/manifest.d/learning.json`, `src/registry.js`
- [ ] Implement
- [ ] Test: `tests/Unit/Controller/CredentialReissueControllerTest.php` (instructor refused); Playwright `tests/e2e/credential-reissue.spec.ts`; hydra gates 5, 7, 30

### Task 4: Wallet status and learner notification
- **spec_ref**: `specs/certification/spec.md#requirement-a-learner-hears-that-their-certificate-was-reissued`
- **files**: `lib/Service/CredentialReissueService.php`, register notification block
- [ ] Implement
- [ ] Test: unit test on the wallet status reset; gate 18 (notification dialect)

### Task 5: Seed data and translations
- **files**: training example set generator, `l10n/en.json`, `l10n/nl.json`, `l10n/*.js`
- [ ] Implement
- [ ] Test: gate 101, `npm run check:schema-l10n`, `npm run check:l10n-js`

## Verification
- `openspec validate credentials-bulk-reissue --strict` passes
- `composer check:strict` and `npm run lint` show no new finding against the development baseline
