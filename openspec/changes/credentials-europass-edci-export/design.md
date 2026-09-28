# Design: credentials-europass-edci-export

## Context

`Credential` carries an Open Badges 3.0 assertion and a nullable `edciPayload` that nothing writes (`lib/Settings/learniq_register.json:325` onwards). Signing lives in `CredentialSigningService` (`lib/Service/CredentialSigningService.php`): `buildOb3Payload()` (:179) assembles the assertion, `signPayload()` (:247) produces an RS256 compact JWS with the tenant key held encrypted in app config, and `resolveIssuerDid()` (:388) derives `did:web:learniq:<tenant>:<fingerprint>`. The EUDI wallet delegate already prefers `edciPayload` (`lib/Service/WalletOfferDelegationService.php:248`).

## Step 0: prove the signing path

Before the EDCI builder is wired, a live issue is run on a local instance: complete an enrolment on a course with a `certificateTemplate` and read the `Credential` that `CredentialIssuanceHandler` (`lib/Listener/CredentialIssuanceHandler.php:81-148`) should create. If it is refused or unsigned (the static reading in proposal.md), the implementer makes the signing explicit: either declare an `issue` transition whose `requires` is `CredentialSigningService`, if OpenRegister fires a transition on create for a lifecycle with `initial` set, or have the handler call the signer and send `signature`, `openbadges3Payload` and `issuerDid` in the same `saveObject()`. The implementer files the defect as its own learniq issue and links it from the PR.

## The payload

`EdciPayloadBuilder::build(array $credential, array $course, array $learner, array $issuer): array` returns a European Digital Credential in the ELM application profile of one pinned version:

| ELM element | source |
|---|---|
| credential `id` | `urn:uuid:<Credential.id>` |
| `issuer` (Organisation) | the tenant's organisation: name, legal identifier (KvK number or BRIN, from app config or the `School` record of school-and-location-records), and the `issuerDid` |
| `validFrom`, `validUntil` | `Credential.issuedAt`, `Credential.expiresAt` |
| `credentialSubject` (Person) | the learner's given and family name from `LearnerProfile`; no national identifier, no date of birth |
| `hasClaim` (LearningAchievement) | title from `Course.name` (and `name_nl` as a second language), awarded by the issuer, awarding date `issuedAt` |
| `specifiedBy` (LearningAchievementSpecification) | `Course.name`, `Course.ectsCredits` as credit points, `Course.educationalLevels` mapped to an EQF or NLQF level where the value maps, `Course.competencyIds` as learning outcomes with their titles |
| `credentialProfiles` | generic, or diploma for `kind: diploma` |

The OB3 payload deliberately carries no personal data, only a hashed subject id (`CredentialSigningService.php:212-213`), because its verification URL is public. The Europass form differs on purpose: it is a file only the learner and staff can download, and the Europass profile needs the holder's name to show whose credential it is. It is never served on a public route.

The builder never invents a value: an element without a source is left out. The pinned ELM version, its JSON-LD context URL and its SHACL shapes are named in the builder's docblock and in a test fixture.

## Signing and storage

`CredentialSigningService` gains `buildAndSignEdci()` that calls the builder, signs the payload with `signPayload()` (same key, same `kid`), and returns `edciPayload` with its proof. It runs where the OB3 payload is produced at issue time. For credentials issued earlier, `POST /api/credentials/{id}/europass` (`hr`, `compliance-officers`) fills `edciPayload` once; it refuses a revoked credential.

## Export

`GET /api/credentials/{id}/europass` returns `edciPayload` as `application/ld+json` with a file name `europass-<course code>-<issued date>.jsonld`. Access: the credential's learner (`Credential.learnerId === $userId`) and the groups that may read `Credential` (`hr`, `compliance-officers`); anyone else gets 404. `#[NoAdminRequired]` with that check in the body (gate 7). On `CredentialDetail` (`src/manifest.d/people.json:531`) a header action "Download for Europass" calls it, and "Create Europass version" shows for staff while `edciPayload` is empty.

## Verification

Today `GET /api/credentials/{id}/verify` answers validity only (`valid`, `issuedAt`, `expiresAt`, `issuerName`; `CredentialVerifyController.php:132-214`), never a payload. This change adds `POST /api/credentials/{id}/verify` (public, like the GET) that takes a Europass file, checks its JWS against the tenant key with the canonicalisation the OB3 check uses (`CredentialVerifyController.php:321-377`), checks that it matches the stored `edciPayload`, and answers with the same validity fields. It never returns the stored payload, so the public route leaks no name.

## Declarative versus imperative

| behaviour | path | reason |
|---|---|---|
| payload assembly and signing | imperative, `EdciPayloadBuilder` and `CredentialSigningService` | ADR-031 exception: document generation with a cryptographic signature, the exception the certification design already took for OB3 (`openspec/changes/archive/certification/design.md:240`) |
| download and backfill routes | imperative controller | a file download and a one-off write with an access check |

## Seed data

In the training example set: a `Credential` for "BHV basisopleiding" (`kind: certificate`, 8 hours, no ECTS) and in the HE set one for "Minor duurzame bedrijfsvoering" (`kind: microcredential`, 15 ECTS), each with an `edciPayload` fixture built by the builder in the test suite and copied into the set.

## Open points

- Which legal identifier the issuer carries for a company segment without a BRIN: the KvK number from app config is the default; the implementer checks the ELM profile's accepted identifier schemes.
