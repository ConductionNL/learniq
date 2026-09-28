# Grading: one learner profile resolver delta

## ADDED Requirements

### Requirement: One resolver finds a learner's profile

`LearnerRefResolver` MUST be the one class that turns a Nextcloud user id into the uuid of that learner's LearnerProfile, and a `learnerRef` into the active profile row, for the grade, submission and portal stamps. A signed-in caller MUST keep OpenRegister's tenant scoping (`resolve()`); a caller without a session MUST read across tenants (`resolveAcrossTenants()`, `byRef()`). `byRef()` MUST return null for a profile that is merged away, deleted, or names no user, and MUST let a read error propagate.

#### Scenario: A portal stamp and a teacher-side stamp find the same profile

- **GIVEN** pupil `pupil-1` with active profile `lp-1`
- **WHEN** a portal attempt is stamped (no session) and a teacher's grade is stamped (signed in)
- **THEN** both get `lp-1` from `LearnerRefResolver`
- **AND** only the portal lookup drops tenant scoping

#### Scenario: A merged-away profile is not a learner to act for

- **GIVEN** profile `lp-old` merged into `lp-new`
- **WHEN** a portal request names `lp-old`
- **THEN** `byRef()` returns null
