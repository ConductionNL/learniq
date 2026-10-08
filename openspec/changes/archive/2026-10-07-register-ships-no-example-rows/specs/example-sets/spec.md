## ADDED Requirements

### Requirement: The register seeds reference rows only
`lib/Settings/learniq_register.json` MUST NOT carry example rows in `components.objects`. It MAY seed shared reference rows that the example sets point at by code (the `regulation` `AVG`). Learners, staff, courses, cohorts, programmes, enrolments and every other example row MUST live in a set under `lib/Settings/profiles/`, so an install holds only the set its admin picked, or nothing.

#### Scenario: A clean primary school install holds no company rows
- **GIVEN** a clean install where the admin loads only the primary school set
- **WHEN** the admin opens the courses, cohorts and learners lists
- **THEN** every row belongs to the primary school set
- **AND** there is no NIS2, AVG or BIO2 course, no "All Employees 2026" cohort and no learner Anna or Bram
- @e2e exclude register file shape, covered by PHPUnit `RegisterSeedObjectsTest`; checked live on a clean instance (po-parent-flows lane report)
