## ADDED Requirements

### Requirement: Completing a course issues its certificate without a staff action

When an `Enrolment` moves from `active` to `completed` and its course has a certificate template, the system MUST create a signed `Credential` for that learner in state `issued`, dated at the completion moment. When the credential cannot be signed, the system MUST save no credential and MUST log that the signing key is missing.

#### Scenario: A learner finishes a course with a certificate

- **GIVEN** a course with a certificate template and a learner with an `active` enrolment in it
- **WHEN** the enrolment moves to `completed`
- **THEN** a `Credential` for that learner exists in state `issued` with `issuedAt` equal to the completion moment
- **AND** it carries a signature, an Open Badges 3.0 payload and the issuer

#### Scenario: No signing key is set up

- **GIVEN** a school without a credential signing key
- **WHEN** a learner's enrolment in a course with a certificate template completes
- **THEN** no credential is saved and the log names the missing signing key

### Requirement: A certificate whose signature was altered is answered as not genuine

The verification route MUST check a certificate's stored signature against the school's key before it answers valid. When the signature does not match the certificate's content, it MUST answer not valid with `signature_invalid`.

#### Scenario: Someone alters the signature of a certificate

- **GIVEN** an issued certificate whose stored signature was changed after issue
- **WHEN** an employer opens its verification link, signed out
- **THEN** the answer says not valid with `signature_invalid`

### Requirement: Hr and compliance officers read every certificate of their school

A user in `hr` or `compliance-officers` MUST be able to read and list every `Credential` of their school, whoever holds it.

#### Scenario: A compliance officer opens a learner's certificate

- **GIVEN** a certificate held by learner `f.elamrani`
- **WHEN** a user in `compliance-officers` lists credentials
- **THEN** that certificate is returned and its details can be opened
