## ADDED Requirements

### Requirement: A behaviour incident is logged with what was done about it

A `BehaviourIncident` MUST record the learner, who reported it, when and where it happened, what happened, its severity, the follow-up actions and the resolution. It MUST run `open`, `in-handling` and `resolved`. It MUST NOT be append-only, so its handling transitions run, and the audit trail MUST keep each version.

#### Scenario: A mentor logs an incident and its follow-up

- **GIVEN** a learner in the mentor's group
- **WHEN** the mentor logs an incident with severity `medium`, starts handling it, adds the follow-up "Gesprek met ouders" and resolves it
- **THEN** the incident is `resolved` with that follow-up, and its history shows each step
