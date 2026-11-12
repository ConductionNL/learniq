## ADDED Requirements

### Requirement: A learner confirms and signs their own attestation after the training

When a learner finishes a mandatory compliance lesson, the system MUST ask the learner to confirm that they read and understood the training. The learner MUST be able to sign that attestation themselves, and the `sign` transition MUST then record their confirmation with the signed evidence.

#### Scenario: A learner signs after finishing the training

- **GIVEN** a learner who completed a mandatory compliance lesson and has a `drafted` `Attestation` for it
- **WHEN** the learner confirms they read and understood the training and chooses to sign
- **THEN** the attestation moves to `signed` in the learner's own name
- **AND** the signed record carries the learner's confirmation, the timestamp and the signature

#### Scenario: A learner who has not finished cannot sign

- **GIVEN** a learner with no completion statement for the lesson
- **WHEN** the learner tries to sign the attestation
- **THEN** the signing is refused with a message that the lesson is not completed yet

### Requirement: The audit pack report says the evidence chain is intact when nothing changed

The `signature-verification.txt` file in an exported audit pack MUST state status `valid`, the number of entries verified and the sentence "All HMAC signatures verified. Evidence log is intact." when the HMAC chain over the exported range has no break. When the chain breaks, it MUST state status `broken` and name the entry id where it broke.

#### Scenario: An auditor reads an untouched pack

- **GIVEN** a regulation whose evidence entries in the period were never changed after they were recorded
- **WHEN** an authorised officer exports the audit pack and the auditor opens `signature-verification.txt`
- **THEN** the report shows status `valid`, the number of entries verified and "Evidence log is intact."

#### Scenario: An auditor reads a pack with a broken chain

- **GIVEN** an evidence entry in the period that was changed after it was recorded
- **WHEN** the auditor opens `signature-verification.txt` in the exported pack
- **THEN** the report shows status `broken` and names the entry id where the chain broke
