## ADDED Requirements

### Requirement: Staff see the unauthorised hours against the limit before a flag exists

For each pupil with an attendance threshold, `attendance-summary` MUST carry the lesuren without permission in the threshold's window ending today, the limit, and a line "{n} van {limit} uur". A pupil whose count is at or above the threshold's `warnAtHours` and under its limit MUST be listed as nearing the limit to the teachers of the pupil's groups, the mentor and the head of the school. Reaching the warning level MUST NOT create an `attendance-flag` or any report.

#### Scenario: Sem at 14 of 16
- **GIVEN** Sem missed 14 lesuren without permission in the last four weeks, and the threshold warns at 12
- **WHEN** his teacher opens Today
- **THEN** Sem is listed with "14 van 16 uur" and no flag exists for him
- @e2e exclude spec-only proposal; the recount is asserted in its unit tests

#### Scenario: The count drops
- **GIVEN** Finn is at 12 of 16
- **WHEN** two of his hours are excused afterwards
- **THEN** his count reads 10 of 16 and he is no longer listed
- @e2e exclude as above
