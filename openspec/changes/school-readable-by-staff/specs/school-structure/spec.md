## ADDED Requirements

### Requirement: Staff read the School schema through its own authorization

The `School` schema MUST declare its own `authorization` block granting `read` to the staff groups (`instructors`, `hr`, `compliance-officers`, `team-leads`, `coordinators`, `administration-managers`), so a signed-in staff member reads the school regardless of how OpenRegister applies multitenancy to a register-level role. `create` and `update` MUST stay with `instructors`, `hr`, `compliance-officers` and `team-leads`, and no group but administrators MAY delete.

#### Scenario: A teacher reads the school
- GIVEN a teacher in `instructors`
- WHEN they list `learniq/school`
- THEN the school is returned
- @e2e exclude declarative register shape pinned by `SchoolStaffReadRegisterTest`; live-checked on the primary-school instance
