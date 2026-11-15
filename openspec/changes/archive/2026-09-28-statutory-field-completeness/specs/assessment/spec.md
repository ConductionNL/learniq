## ADDED Requirements

### Requirement: AssessmentResult tracks referentieniveau

`AssessmentResult` MUST carry a nullable `referentieniveau` property (enum: `1F`, `1S`, `2F`, `2S`, `3F`,
`3S` — the standard Dutch referentieniveau taxonomy) so a learner's achieved reference level per attempt can
be tracked over time by querying their `AssessmentResult` history (finding 6.8).

#### Scenario: An AssessmentResult records the referentieniveau achieved

- **GIVEN** an `AssessmentResult` for a taal or rekenen assessment
- **WHEN** it is saved with `referentieniveau: "1F"`
- **THEN** the value is persisted and readable on that attempt

#### Scenario: A learner's referentieniveau history is visible over time

- **GIVEN** a learner with multiple `AssessmentResult` rows across different academic periods, each carrying
  a `referentieniveau`
- **WHEN** those rows are queried by `learnerId`
- **THEN** the progression of referentieniveau values over time is visible — no new schema or mechanism is
  needed beyond the existing append-only `AssessmentResult` history
