# Tasks: credentials-europass-edci-export

## Implementation tasks

### Task 1: Prove or repair the signing path
- **spec_ref**: `specs/certification/spec.md#requirement-an-issued-certificate-carries-a-signed-europass-form`
- **files**: `lib/Listener/CredentialIssuanceHandler.php`, `lib/Settings/learniq_register.json` (Credential lifecycle), a new learniq issue for the defect
- **acceptance_criteria**:
  - GIVEN a completed enrolment on a course with a certificate template WHEN issuance runs on a live instance THEN a Credential exists with `signature`, `openbadges3Payload` and `issuerDid`
- [x] Implement
- [ ] Test: `tests/Unit/Listener/CredentialIssuanceHandlerTest.php` asserts the signed fields are sent; one live issue recorded in the PR body
- Repaired by #1178 (merged 2026-09-28, "sign a credential before it is saved"): `CredentialIssuanceHandler::saveSignedCredential()` calls `CredentialSigningService::sign()` before the save, and `CredentialIssuanceHandlerTest::testACompletedEnrolmentSavesACredentialWithEveryRequiredProperty` asserts every required signed field. The live issue on an instance is not run in this lane (no instance), so the test line stays open for that half.

### Task 2: EDCI payload builder
- **spec_ref**: `specs/certification/spec.md#requirement-an-issued-certificate-carries-a-signed-europass-form`
- **files**: `lib/Service/EdciPayloadBuilder.php`, `tests/fixtures/edci/`
- **acceptance_criteria**:
  - GIVEN the fixture credential WHEN built THEN the output validates against the pinned ELM SHACL shapes
  - GIVEN a course without ECTS WHEN built THEN no credit element is present
- [x] Implement
- [x] Test: `tests/Unit/Service/EdciPayloadBuilderTest.php`
- Pinned to the EDC application profile of ELM 3.2 (context `http://data.europa.eu/snb/model/context/edc-ap`); the unit test holds the output to the fixture `tests/fixtures/edci/microcredential.json` instead of the SHACL shapes (no SHACL engine in the PHP test suite). Only vocabulary URIs that are certain are used (EQF levels, the generic credential profile); the ECTS framework is a notation, not an invented URI.

### Task 3: Sign and store at issue time
- **spec_ref**: `specs/certification/spec.md#requirement-an-issued-certificate-carries-a-signed-europass-form`
- **files**: `lib/Service/CredentialSigningService.php`
- [x] Implement
- [x] Test: `tests/Unit/Service/CredentialSigningServiceTest.php` (EDCI signed with the same key and kid as OB3)
- Signed through the new `CredentialSigningService::proofFor()` (the proof block `check()` now uses too), from `EuropassIssuer`, called in `CredentialIssuanceHandler`. Test: `CredentialIssuanceHandlerTest::testACertificateIsIssuedWithASignedEuropassForm` (same verificationMethod, so the same key and kid).

### Task 4: Backfill and download routes
- **spec_ref**: `specs/certification/spec.md#requirement-a-learner-downloads-their-certificate-for-europass`, `#requirement-staff-create-the-europass-form-for-an-earlier-certificate`
- **files**: `lib/Controller/CredentialEuropassController.php`, `appinfo/routes.php`
- [x] Implement
- [x] Test: `tests/Unit/Controller/CredentialEuropassControllerTest.php` (own learner, other learner 404, revoked refused); hydra gates 5, 7, 30
- `CredentialEuropassController`: `GET /api/credentials/{id}/europass` (and a `POST .../europass/download` alias for the page action), `POST /api/credentials/{id}/europass` (backfill).

### Task 5: Verification of the Europass form
- **spec_ref**: `specs/certification/spec.md#requirement-the-europass-form-is-checkable-on-the-verification-route`
- **files**: `lib/Controller/CredentialVerifyController.php`
- [x] Implement
- [x] Test: `tests/Unit/Controller/CredentialVerifyControllerTest.php` with a tampered payload
- `POST /api/credentials/{id}/verify` (`verifyEuropass`); the JWS helpers moved into `JwsProofVerifier`, shared with the GET. Tests: unchanged file valid with no personal data in the answer, tampered file `not_matching`, forged signature `signature_invalid`.

### Task 6: Credential page actions, seed data, translations
- **files**: `src/manifest.d/people.json`, example set generators, `l10n/en.json`, `l10n/nl.json`, `l10n/*.js`
- [x] Implement
- [ ] Test: Playwright `tests/e2e/credential-europass.spec.ts` (learner downloads); gate 101; `npm run check:schema-l10n`

## Verification
- `openspec validate credentials-europass-edci-export --strict` passes
- `composer check:strict` and `npm run lint` show no new finding against the development baseline
- Header actions "Download for Europass" and "Create Europass version" on CredentialDetail, catalogue strings, teacher guide section. The Playwright test is not written (no live instance); gate 101 and `check:schema-l10n` pass. No seed row carries an `edciPayload`: a demo payload with a signature no tenant key verifies would fail the verify route.

