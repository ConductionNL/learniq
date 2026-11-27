## ADDED Requirements

### Requirement: A decided study decision keeps the credits and warnings it rests on

When a `BsaDecision` moves to `decided`, the stored record MUST keep `ectsAchieved`, `ectsNormRequired` and `warningIds` as they were at the moment of decision. These fields MUST be part of the signed payload, so a later change shows up as a broken signature.

#### Scenario: A coordinator records a negative decision

- **GIVEN** learner Daan with 38 credits against a norm of 45, and an issued warning w1 for this programme and year
- **WHEN** the coordinator decides a negative `BsaDecision` with `ectsAchieved` 38, `ectsNormRequired` 45 and `warningIds` [w1]
- **THEN** the decided record stores 38, 45 and [w1]
- **AND** it carries a signature over those values
