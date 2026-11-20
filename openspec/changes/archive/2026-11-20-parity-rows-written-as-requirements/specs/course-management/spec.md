## ADDED Requirements

### Requirement: A course is worked on in draft and published when ready

A `Course` MUST start in `draft`. Learners MUST NOT see a draft course in the catalogue. The `publish` transition (`draft` to `published`) MUST pass `CoursePublishGuard`. A published course MAY be archived, and an archived course MAY return to `draft`.

#### Scenario: A new course stays out of sight until it is published

- **GIVEN** a teacher creates the course "Excel basis"
- **WHEN** a learner opens the catalogue
- **THEN** "Excel basis" is not listed and the course is in `draft`

#### Scenario: Publishing makes the course available

- **GIVEN** the course "Excel basis" in `draft` that passes `CoursePublishGuard`
- **WHEN** the teacher publishes it
- **THEN** the course is `published`

### Requirement: A course carries a Dutch name next to its English name

A `Course` MUST be able to carry `name_nl` next to `name`, and a `language`. The course settings form MUST offer both names, and both MUST be stored on the course.

#### Scenario: A teacher gives a course its Dutch name

- **GIVEN** a course with `name` "Working at height"
- **WHEN** the teacher enters "Werken op hoogte" as the Dutch name and saves
- **THEN** the course stores `name` "Working at height" and `name_nl` "Werken op hoogte"
