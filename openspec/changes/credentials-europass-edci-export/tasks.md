# Tasks: credentials-europass-edci-export

## Implementation tasks

### Task 1: Prove or repair the signing path
- **spec_ref**: `specs/certification/spec.md#requirement-an-issued-certificate-carries-a-signed-europass-form`
- **files**: `lib/Listener/CredentialIssuanceHandler.php`, `lib/Settings/learniq_register.json` (Credential lifecycle), a new learniq issue for the defect
- **acceptance_criteria**:
  - GIVEN a completed enrolment on a course with a certificate template WHEN issuance runs on a live instance THEN a Credential exists with `signature`, `openbadges3Payload` and `issuerDid`
- [ ] Implement
- [ ] Test: `tests/Unit/Listener/CredentialIssuanceHandlerTest.php` asserts the signed fields are sent; one live issue recorded in the PR body

### Task 2: EDCI payload builder
- **spec_ref**: `specs/certification/spec.md#requirement-an-issued-certificate-carries-a-signed-europass-form`
- **files**: `lib/Service/EdciPayloadBuilder.php`, `tests/fixtures/edci/`
- **acceptance_criteria**:
  - GIVEN the fixture credential WHEN built THEN the output validates against the pinned ELM SHACL shapes
  - GIVEN a course without ECTS WHEN built THEN no credit element is present
- [ ] Implement
- [ ] Test: `tests/Unit/Service/EdciPayloadBuilderTest.php`

### Task 3: Sign and store at issue time
- **spec_ref**: `specs/certification/spec.md#requirement-an-issued-certificate-carries-a-signed-europass-form`
- **files**: `lib/Service/CredentialSigningService.php`
- [ ] Implement
- [ ] Test: `tests/Unit/Service/CredentialSigningServiceTest.php` (EDCI signed with the same key and kid as OB3)

### Task 4: Backfill and download routes
- **spec_ref**: `specs/certification/spec.md#requirement-a-learner-downloads-their-certificate-for-europass`, `#requirement-staff-create-the-europass-form-for-an-earlier-certificate`
- **files**: `lib/Controller/CredentialEuropassController.php`, `appinfo/routes.php`
- [ ] Implement
- [ ] Test: `tests/Unit/Controller/CredentialEuropassControllerTest.php` (own learner, other learner 404, revoked refused); hydra gates 5, 7, 30

### Task 5: Verification of the Europass form
- **spec_ref**: `specs/certification/spec.md#requirement-the-europass-form-is-checkable-on-the-verification-route`
- **files**: `lib/Controller/CredentialVerifyController.php`
- [ ] Implement
- [ ] Test: `tests/Unit/Controller/CredentialVerifyControllerTest.php` with a tampered payload

### Task 6: Credential page actions, seed data, translations
- **files**: `src/manifest.d/people.json`, example set generators, `l10n/en.json`, `l10n/nl.json`, `l10n/*.js`
- [ ] Implement
- [ ] Test: Playwright `tests/e2e/credential-europass.spec.ts` (learner downloads); gate 101; `npm run check:schema-l10n`

## Verification
- `openspec validate credentials-europass-edci-export --strict` passes
- `composer check:strict` and `npm run lint` show no new finding against the development baseline
