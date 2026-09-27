## ADDED Requirements

### Requirement: LearnerProfile records the NOAT/CUMI/NNCA funding-weight classification
`LearnerProfile` SHALL gain `fundingWeightCode` (nullable enum `noat | cumi | nnca`, default `null`) — the
culturele-achtergrond classification that feeds the ROD/bekostiging funding weging (P-new-13). This is additive:
no existing `LearnerProfile` object is affected, and the field is independent of any other property.

#### Scenario: A school records a learner's funding-weight classification
- **GIVEN** a `LearnerProfile` with `fundingWeightCode: null`
- **WHEN** staff set it to `"cumi"`
- **THEN** the property persists and feeds the same ROD/bekostiging chain P-new-12's teldatum check protects

<!-- @e2e exclude Schema-shape requirement, verified by FundingTeldatumRegisterTest; no bespoke controller — reads/writes go through OpenRegister's generic object endpoint per ADR-022. -->
