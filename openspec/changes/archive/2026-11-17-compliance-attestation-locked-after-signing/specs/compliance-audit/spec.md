## ADDED Requirements

### Requirement: A signed attestation cannot be edited

The system MUST refuse any update that changes an evidence field of an `Attestation` in state `signed` or `revoked`. The refusal MUST apply to every user, administrators included. The evidence fields are `learnerId`, `lessonId`, `courseId`, `regulationSlug`, `actorIp`, `employeeId`, `score`, `xapiStatementId`, `signature`, `signingKeyId`, `signedAt` and `tenant_id`. An attestation in state `drafted` MUST stay editable for hr and compliance-officers.

#### Scenario: A compliance officer edits a signed attestation

- **GIVEN** an attestation in state `signed`
- **WHEN** a compliance officer saves it with a different `score`
- **THEN** the save is refused with a message that a signed attestation cannot be changed
- **AND** the stored attestation keeps its original `score` and `signature`

#### Scenario: An administrator edits a signed attestation

- **GIVEN** an attestation in state `signed`
- **WHEN** a Nextcloud administrator saves it with a different `learnerId`
- **THEN** the save is refused

#### Scenario: A drafted attestation is corrected

- **GIVEN** an attestation in state `drafted`
- **WHEN** an hr user changes its `courseId`
- **THEN** the save succeeds

### Requirement: Revoking a signed attestation records a reason and changes nothing else

The `revoke` transition MUST require a non-empty `revocationReason` and MUST stay available to hr and compliance-officers. It MUST write only `lifecycle` and `revocationReason`. The signing transition MUST stamp `signedAt`.

#### Scenario: Revoke with a reason

- **GIVEN** an attestation in state `signed`
- **WHEN** a compliance officer runs `revoke` with the reason "Signed for the wrong course"
- **THEN** the attestation moves to `revoked` with that reason
- **AND** its evidence fields and signature are unchanged

#### Scenario: Revoke without a reason

- **GIVEN** an attestation in state `signed`
- **WHEN** a compliance officer runs `revoke` without a reason
- **THEN** the transition is refused

### Requirement: The audit pack states the freeze

The audit pack MUST state, for each signed or revoked attestation, its `signedAt` and that its evidence fields have been frozen since then.

#### Scenario: An auditor opens the pack

- **GIVEN** an audit pack exported for a regulation with two signed attestations
- **WHEN** the auditor opens `signature-verification.txt`
- **THEN** each attestation line shows its `signedAt` and the words "frozen since signing"
