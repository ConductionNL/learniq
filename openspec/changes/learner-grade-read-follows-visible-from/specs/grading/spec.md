## ADDED Requirements

### Requirement: A learner reads their own grade only once it is released

A learner MUST read their own `GradeEntry` only when its `lifecycle` is `published` or `revised` and its `visibleFrom` is at or before the current time, or not set. The rule MUST be the schema's read authorization, so OpenRegister applies it before data is returned on the single-object read, the list query and every page built on them. Staff read access MUST stay as it is.

#### Scenario: A grade held back until tomorrow
- **GIVEN** a published GradeEntry of learner Fenna with `visibleFrom` tomorrow at 08:00
- **WHEN** Fenna lists her grade entries through the objects API or opens /my-grades
- **THEN** the entry is not in the answer
- **AND** reading it by id returns no grade

#### Scenario: A grade whose moment has passed
- **GIVEN** a published GradeEntry of learner Fenna with `visibleFrom` yesterday
- **WHEN** Fenna lists her grade entries
- **THEN** the entry is in the answer

#### Scenario: A concept grade
- **GIVEN** a concept GradeEntry of learner Fenna
- **WHEN** Fenna lists her grade entries
- **THEN** the entry is not in the answer

#### Scenario: A teacher reads before the moment
- **GIVEN** a published GradeEntry with `visibleFrom` tomorrow
- **WHEN** a member of `instructors` reads it
- **THEN** the entry is returned
