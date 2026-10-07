## ADDED Requirements

### Requirement: An invited trainer may sign a praktijkovereenkomst

The portal action `signPraktijkovereenkomst` MUST accept a session at `minTrust: low` and MUST post to learniq's own endpoint, because only a forwarded request carries the assertion that names the session's assurance. The endpoint MUST take the signer from the assertion's claim and MUST write `signerId`, `signerRole`, `signedAt`, `method` and `assuranceLevel` itself; a value sent for any of them MUST be replaced.

#### Scenario: A trainer who signed in from her invitation signs
- GIVEN Karin signed in without eHerkenning, so her session is `low`
- WHEN she signs the praktijkovereenkomst of her own student
- THEN the signature is stored with `assuranceLevel: basic` and her own praktijkopleider record as signer
- @e2e tests/e2e/trainer-flows.spec.ts

#### Scenario: The client cannot claim a stronger signature
- GIVEN the same session sends `assuranceLevel: high` in the body
- WHEN the signature is written
- THEN the stored row says `basic`
- @e2e exclude asserted on the real payload against the shipped schema fragment

#### Scenario: Another student's agreement is refused
- GIVEN a placement whose praktijkopleider is somebody else
- WHEN Karin signs it
- THEN the signature is refused and nothing is written
- @e2e tests/e2e/trainer-flows.spec.ts

### Requirement: A school may still demand eHerkenning for a praktijkovereenkomst

`bpv_pok_min_assurance` MUST be read on every signature and MUST default to `basic`. A signature whose assurance is below the configured floor MUST be refused, and the refusal MUST name the level required.

#### Scenario: A school that demands substantial
- GIVEN `bpv_pok_min_assurance` is `substantial`
- WHEN Karin signs from a session at `basic`
- THEN the signature is refused, and the answer names `substantial` as what is required
- @e2e tests/e2e/trainer-flows.spec.ts

#### Scenario: The default school
- GIVEN the setting was never written
- WHEN Karin signs from a session at `basic`
- THEN the signature is stored
- @e2e exclude the default is asserted in the service's unit tests
