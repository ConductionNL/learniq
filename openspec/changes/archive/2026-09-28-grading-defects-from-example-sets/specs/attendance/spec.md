# Attendance: neutral flag kind delta

## ADDED Requirements

### Requirement: An attendance flag outside the leerplicht carries a neutral kind

`AttendanceFlag.flagKind` MUST offer `attendance-requirement` next to the school concerns (`signal-verzuim`, `langdurig-relatief-verzuim`, `thuiszitter`), for a learner who falls below an attendance requirement of a course, programme or training that is not a statutory school concern. The flag created for a threshold crossing MUST take its kind from the threshold: `leerplicht-16uur` gives `signal-verzuim`; `college-aanwezigheid`, `training-attendance` and `compliance-presence` give `attendance-requirement`. A `generic` threshold MUST keep the schema default, so existing school installs see no change.

#### Scenario: A university workgroup requirement is not a leerplicht signal

- **GIVEN** an active AttendanceThreshold with `kind: college-aanwezigheid`
- **WHEN** a guarded `check-threshold` records a crossing for a student
- **THEN** the created AttendanceFlag has `flagKind: attendance-requirement`

#### Scenario: A company compliance presence requirement is neutral

- **GIVEN** an active AttendanceThreshold with `kind: compliance-presence`
- **WHEN** a crossing is recorded for an employee
- **THEN** the created AttendanceFlag has `flagKind: attendance-requirement`

#### Scenario: The leerplicht profile still raises a verzuim signal

- **GIVEN** an active AttendanceThreshold with `kind: leerplicht-16uur`
- **WHEN** a crossing is recorded for a pupil
- **THEN** the created AttendanceFlag has `flagKind: signal-verzuim`
