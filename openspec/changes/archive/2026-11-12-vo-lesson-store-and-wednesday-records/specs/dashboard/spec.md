## ADDED Requirements

### Requirement: The HR Today subtitle names the school

The Today page MUST end an HR employee's subtitle with the name of the school when the instance holds exactly one `school` row the reader can see. With no school, several schools or a failed read, the subtitle MUST stay the line without a name. The page MUST read the school only for a role whose manifest config has a line that names it.

#### Scenario: One school
- **GIVEN** an instance with the one school Zuiddrecht College
- **WHEN** an HR employee opens Today in Dutch
- **THEN** the line after the date reads "hr-medewerker · schoolbreed, alle locaties van Zuiddrecht College"
- @e2e exclude covered by `tests/unit-js/schoolName.test.mjs`; measured live on LqHrVandaag

#### Scenario: Several schools
- **GIVEN** an instance with two schools
- **WHEN** an HR employee opens Today in Dutch
- **THEN** the line after the date reads "hr-medewerker · schoolbreed, alle locaties"
- @e2e exclude covered by `tests/unit-js/schoolName.test.mjs`
