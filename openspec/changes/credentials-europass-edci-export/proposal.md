---
kind: code
depends_on: []
---

# Proposal: credentials-europass-edci-export

## Summary

Every certificate learniq issues gets a second, European form next to its Open Badges 3.0 badge: a European Digital Credential in the European Learning Model (ELM) that a learner downloads and keeps in their Europass profile, signed with the same tenant key and checkable on learniq's existing verification route. Certificates issued before this change get the Europass form on request from the credential page.

## Why

Matrix: learniq `openspec/parity/capabilities.json`, row `cred-europass-edci` ("Export a certificate in the European Europass credential format."), rated `no`, `built.state: none`, built evidence "lib/Settings/learniq_register.json Credential.properties.edciPayload is declared and marked Phase 3; nothing in lib/ writes it". Decision: build, because the row sits in learniq's core area (credentials is one of the two areas of the matrix's first 30 rows). No competitor rates yes (moodle, ilias and chamilo no; moodle-workplace, totara and ispring-learn unknown) and there is no demand row, so the core area rule is the only reason; the proposal says so plainly.

Learniq has already promised this twice:

- `openspec/specs/certification/spec.md:33-39`, requirement "Issue EDCI/Europass and Open Badges 3.0 credentials": "The system MUST issue EDCI / Europass credentials and Open Badges 3.0 with verifiable URLs."
- `openspec/changes/archive/certification/proposal.md:32`: "full EDCI/Europass signing with DID + linked-data proof is deferred to Phase 3", and `design.md:251` lists "EDCI/Europass ELM encoding | Phase 3".
- `openspec/changes/archive/2026-07-16-portable-learning-record/proposal.md:68-71` imports ELM files and says its export "does not claim EDCI issuance is complete".

## What learniq has today

Read at learniq `development` a84b6273.

- `lib/Settings/learniq_register.json:325` `Credential` (0.2.0): `openbadges3Payload` (required), `edciPayload` ("EDCI/Europass ELM payload. Phase 3.", nullable), `signature` (required), `issuerDid` (required), `verificationUrl`, `kind` (`diploma`, `certificate`, `badge`, `microcredential`).
- `lib/Service/CredentialSigningService.php:93` `check()` builds the OB3 payload (`buildOb3Payload()`, :179), signs it (`signPayload()`, :247) and resolves a synthetic `did:web` issuer (`resolveIssuerDid()`, :388-404). It is written as the `requires` guard of an `issue` transition.
- `lib/Service/WalletOfferDelegationService.php:248` already prefers `edciPayload` over `openbadges3Payload` when it offers a credential to the EUDI wallet, and names the configuration `edci-diploma` for diplomas and certificates (:267), so the wallet path is ready for the payload this change writes.
- `GET /api/credentials/{id}/verify` (`appinfo/routes.php:43`, `CredentialVerifyController`) verifies the JWS signature.
- `CredentialDetail` (`src/manifest.d/people.json:531`, `/credentials/:id`) shows the credential with a files leaf.

### A defect this change depends on

`Credential`'s `x-openregister-lifecycle` declares `revoke`, `expire`, `offerToWallet` and `recordWalletClaim`, and no `issue` transition. `CredentialIssuanceHandler.php:128-131` relies on OpenRegister "auto-firing the `issue` transition from null" to run `CredentialSigningService::check()`, while `Credential.required` lists `signature`, `openbadges3Payload` and `issuerDid`, which the handler's `saveObject()` (:133-147) does not send. On this reading the signing guard has no transition to run on, and an automatic issue would be refused for missing required fields. This is a static reading; task 1 checks it on a live instance before anything else is built.

## What this change builds

1. `EdciPayloadBuilder`: maps a credential, its course, the learner and the issuing organisation onto an ELM European Digital Credential (JSON-LD, W3C verifiable credential data model), with the achievement, its credits, level and learning outcomes.
2. Signing: the EDCI payload is signed with the same tenant key and algorithm as the OB3 payload and stored in `edciPayload`, at issue time for new credentials.
3. A "Create Europass version" action on `CredentialDetail` for `hr` and `compliance-officers`, for credentials issued before this change.
4. `GET /api/credentials/{id}/europass`: the signed credential as a JSON-LD download, for the learner it belongs to and for staff who may read it.
5. An employer or school can check a Europass file on learniq's verification route: learniq checks the signature and the credential's state and answers valid or not valid, without ever returning the file's content.

## Out of scope

- A qualified electronic seal. The Europass viewer shows a credential as authentic only when an organisation's qualified eSeal signs it; that comes from a qualified trust service provider outside the fleet. Learniq's own signature makes the credential checkable on learniq's verification route; the seal is a follow-up once a school has a seal provider.
- The Bologna Diploma Supplement.
- Issuing through the Europass issuer portal.

## Affected projects

- [x] `learniq`: `lib/Service/EdciPayloadBuilder.php`, `lib/Service/CredentialSigningService.php`, `lib/Controller/CredentialVerifyController.php` or a new export controller, `appinfo/routes.php`, `src/manifest.d/people.json`, seed data.

## Risks

- ELM is a large model and moves by version. Mitigation: the builder pins one ELM version and validates its output against that version's published SHACL shapes in a unit test with a fixture.
- Personal data in a portable file. Mitigation: unlike the OB3 payload, the Europass form names the holder, so only the learner and staff who may read the credential can download it, the public verification route never returns it, and date of birth and national identifiers are left out.
