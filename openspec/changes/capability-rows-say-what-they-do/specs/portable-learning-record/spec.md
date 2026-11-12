## ADDED Requirements

### Requirement: A learner downloads the signed bundle of their record and it carries a verifiable signature

When a learner generates an export, learniq MUST store the signed bundle in the learner's own Files and show the learner where it is. The stored bundle MUST carry its own `proof` with the compact JWS, so anyone holding the file can verify it against the tenant's public key without asking learniq.

#### Scenario: A learner takes their record to a new school

- **GIVEN** a learner on "My learning record"
- **WHEN** they choose "Generate export"
- **THEN** the page says the export was generated and names where the signed bundle is in their Files
- **AND** the learner can download that file from Files

#### Scenario: The downloaded bundle verifies on its own

- **GIVEN** the bundle file the learner downloaded
- **WHEN** a receiving school checks the JWS in its `proof` against the tenant's public key
- **THEN** the signature verifies, and a bundle with one changed field does not verify
