## ADDED Requirements

### Requirement: An attendance mark records late and excused next to present and absent

An `AttendanceRecord` MUST carry a `status` of `present`, `absent-unexcused`, `absent-excused`, `late` or `left-early`. A `late` mark MAY carry `lateMinutes`. Only `absent-unexcused` MUST count as an unexcused absence.

#### Scenario: A teacher marks a learner late

- **GIVEN** a lesson in progress
- **WHEN** the teacher marks a learner `late` with 10 minutes
- **THEN** the record has status `late` and `lateMinutes` 10, and it does not count as an unexcused absence

#### Scenario: An excused absence

- **GIVEN** an approved absence note for a learner
- **WHEN** the learner's attendance for that lesson is read
- **THEN** the status is `absent-excused`
