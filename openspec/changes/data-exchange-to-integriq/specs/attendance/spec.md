# attendance delta: data-exchange-to-integriq

## ADDED Requirements

### Requirement: The municipality's feedback on a leerplicht report is recorded on the attendance flag
A coordinator or administrator MUST record the municipality's case route (MAS route) on the reported `AttendanceFlag` through a `recordMunicipalityFeedback` transition that keeps the flag `reported`. The actor and the time MUST be stamped server-side. The report itself is sent by integriq; the flag moves to `reported` only once integriq concluded its job `succeeded`.

#### Scenario: a coordinator records the MAS route
- GIVEN an attendance flag in `reported`
- WHEN a coordinator records the municipality's feedback with MAS route "casusoverleg"
- THEN the flag stays `reported` and `municipalityFeedback.recordedBy` is that coordinator

#### Scenario: the report is not sent yet
- GIVEN an attendance flag in `in-handling` whose integriq job is `queued`
- WHEN someone tries to move it to `reported`
- THEN the transition is refused until integriq concluded the job `succeeded`
