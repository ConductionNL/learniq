# BPV: parent or guardian signs a minor's praktijkovereenkomst delta

## RENAMED Requirements

- FROM: `### Requirement: POK activation is gated on all three signatures`
- TO: `### Requirement: POK activation is gated on every required signature`

## MODIFIED Requirements

### Requirement: Three-party POK signing reuses the Signature pattern via PokSignature

`Praktijkovereenkomst` signing MUST use a `PokSignature` schema shaped identically to the `learning-plan` `Signature` schema (`subjectId`, `subjectVersion`, `signerId`, `signerRole`, `signedAt`, `assuranceLevel`, `method`, `evidenceRef`, append-only), with `signerRole` restricted to `student | school | praktijkopleider | parent`. `parent` is a parent or guardian of a student who is under 18, signing next to the student. The existing `Signature` schema MUST NOT be widened (its `subjectId` is hard-`$ref`'d to `LearningPlan`; widening it to a polymorphic subject would violate the fleet's single-schema relation-dialect rule).

#### Scenario: A POK version requires all three roles signed
- **GIVEN** a `Praktijkovereenkomst` version with a `PokSignature` from `student` and `school` recorded
- **WHEN** the `praktijkopleider` signs (via the portal)
- **THEN** a third `PokSignature` is recorded, append-only, for that version
- **AND** the prior two signatures remain unchanged

#### Scenario: A parent signs a minor's agreement
- **GIVEN** a `Praktijkovereenkomst` for a student aged 17
- **WHEN** a parent listed on the student's learner profile signs on the signing page
- **THEN** a `PokSignature` with `signerRole: parent` is recorded, append-only, for that version

### Requirement: POK activation is gated on every required signature

`Praktijkovereenkomst` MUST NOT transition to `active` unless a `PokSignature` exists for each of `student`, `school`, and `praktijkopleider` on the current version, enforced by `PokActivationGuard` and reflected in the `isFullySigned` calculation. When the student was under 18 on the day of their own signature, or no date of birth is recorded, a `parent` signature MUST exist too, and it MUST count only when its `signerId` is in the student's `LearnerProfile.parentIds`. The guard MUST derive this from the placement's learner and the student's signature itself; it MUST NOT rely on a flag stored on the POK. A student who turns 18 after signing still needs the parent signature, because the age at signing decides.

The POK MUST carry `parentSignatureRequired`, set by the server when signatures are requested and again on activation, from the same rule, so the signing flow can ask for the parent. `isFullySigned` MUST require a parent signature when `parentSignatureRequired` is true.

#### Scenario: Activation blocked until fully signed
- **GIVEN** a `Praktijkovereenkomst` missing the `praktijkopleider` signature
- **WHEN** an attempt is made to activate it
- **THEN** `PokActivationGuard` blocks the transition and `isFullySigned` is `false`
- **AND** once the missing signature is recorded, `isFullySigned` becomes `true` and activation succeeds

#### Scenario: A minor's agreement waits for a parent
- **GIVEN** a student born 2009-04-30 who signed on 2025-08-22, and signatures from `student`, `school` and `praktijkopleider`
- **WHEN** the coordinator activates the agreement
- **THEN** the guard refuses and says a parent or guardian listed on the learner profile also signs

#### Scenario: A listed parent's signature completes it
- **GIVEN** the same agreement and a `parent` signature from a user in the student's `parentIds`
- **WHEN** the coordinator activates it
- **THEN** the agreement becomes `active`

#### Scenario: A self-declared parent does not count
- **GIVEN** the same agreement and a `parent` signature from a user who is not in the student's `parentIds`
- **WHEN** the coordinator activates it
- **THEN** the guard refuses

#### Scenario: An adult's agreement needs no parent
- **GIVEN** a student aged 19 on the day of signing, and the three signatures
- **WHEN** the coordinator activates the agreement
- **THEN** it becomes `active`

#### Scenario: An unknown date of birth asks for a parent
- **GIVEN** a student profile without a date of birth, and the three signatures
- **WHEN** the coordinator activates the agreement
- **THEN** the guard refuses and says to record the date of birth when the student is 18 or older

#### Scenario: The signing flow asks for the parent
- **GIVEN** a `Praktijkovereenkomst` for a student aged 16
- **WHEN** the coordinator requests signatures
- **THEN** the POK carries `parentSignatureRequired: true`
- **AND** the signing page offers the parent or guardian role and says a parent or guardian also signs
