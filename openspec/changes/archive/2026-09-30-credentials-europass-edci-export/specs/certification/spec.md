# certification Specification

## ADDED Requirements

### Requirement: An issued certificate carries a signed Europass form

When learniq issues a `Credential` of kind `certificate`, `diploma` or `microcredential`, it MUST also write `edciPayload`: a European Digital Credential in one pinned version of the European Learning Model, built only from values learniq holds (course, learner name, issuer, dates, credits, level, learning outcomes), and signed with the same tenant key and key id as `openbadges3Payload`. An element without a source value MUST be left out, never filled with a placeholder.

#### Scenario: A training certificate gets its Europass form

<!-- @e2e exclude Issue-time payload assembly and signing; covered by EdciPayloadBuilderTest and CredentialSigningServiceTest. -->

- **GIVEN** a learner completes the course "BHV basisopleiding", which has a certificate template and no credits
- **WHEN** learniq issues the certificate
- **THEN** the credential holds a signed `edciPayload` naming the course, the learner and the issuing organisation
- **AND** the payload has no credit element

### Requirement: A learner downloads their certificate for Europass

The learner a credential belongs to, and staff who may read the credential, MUST be able to download its `edciPayload` as a JSON-LD file from the credential page and from `GET /api/credentials/{id}/europass`. Any other caller MUST get a not-found answer.

#### Scenario: A learner saves a certificate to their Europass profile

- **GIVEN** learner f.elamrani with an issued certificate for "Minor duurzame bedrijfsvoering"
- **WHEN** f.elamrani opens the certificate and chooses "Download for Europass"
- **THEN** a file named after the course and the issue date downloads
- **AND** the file is the signed European Digital Credential of that certificate

#### Scenario: Another learner cannot download it

<!-- @e2e exclude Access rule on an endpoint; covered by CredentialEuropassControllerTest::testOtherLearnerGetsNotFound. -->

- **GIVEN** a certificate that belongs to f.elamrani
- **WHEN** another learner requests `GET /api/credentials/{id}/europass`
- **THEN** the answer is not found

### Requirement: Staff create the Europass form for an earlier certificate

Users in `hr` or `compliance-officers` MUST be able to create the Europass form for a credential issued before this change, once, from the credential page. A revoked credential MUST be refused.

#### Scenario: An HR officer backfills an old certificate

- **GIVEN** a certificate issued last year with an empty `edciPayload`
- **WHEN** an HR officer opens it and chooses "Create Europass version"
- **THEN** the certificate shows "Download for Europass"

### Requirement: The Europass form is checkable on the verification route

The verification route MUST accept a Europass file for a credential, check its signature against the tenant key and against the stored `edciPayload`, and answer with validity only. It MUST report a file changed after signing as not valid, and it MUST NOT return the stored payload or any personal data.

#### Scenario: A tampered file fails verification

<!-- @e2e exclude Signature check on a public route; covered by CredentialVerifyControllerTest::testTamperedEdciFails. -->

- **GIVEN** a downloaded Europass file whose achievement title was edited after download
- **WHEN** an employer sends it to `POST /api/credentials/{id}/verify`
- **THEN** the answer says the credential is not valid
- **AND** the answer holds no name and no payload
