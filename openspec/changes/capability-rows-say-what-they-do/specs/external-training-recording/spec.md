## ADDED Requirements

### Requirement: A second officer verifies outside training before it counts toward coverage

A compliance officer, hr member or administrator other than the submitter MUST be able to run `verify` on a `submitted` `ExternalTrainingRecord` that has evidence attached. The record MUST count toward the learner's regulation coverage only once it is `verified`.

#### Scenario: A second officer verifies a learner's certificate

- **GIVEN** learner anna submitted an `ExternalTrainingRecord` for regulation `NIS2` with a certificate attached
- **AND** the coverage view shows anna as uncovered for `NIS2`
- **WHEN** compliance officer bas runs `verify` on the record
- **THEN** the record moves to `verified` with bas as `verifiedBy`
- **AND** the next coverage computation shows anna as covered by external training

#### Scenario: A submitted record does not count yet

- **GIVEN** a `submitted` `ExternalTrainingRecord` for anna and regulation `NIS2` that nobody has verified
- **WHEN** coverage is computed
- **THEN** anna still counts as uncovered for `NIS2`
