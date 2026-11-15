## ADDED Requirements

### Requirement: Cohort declares a kind distinguishing standing care/plusklas subgroups from teaching cohorts

The `Cohort` schema MUST declare a `kind` property (enum: `teaching`, `care`, `plusklas`; default
`teaching`) so a standing, cross-period care or plusklas subgroup (finding 1.14 — ParnasSys "sublesgroep",
ESIS "instructiegroepen") can be represented without requiring a `GroupPlan`, which `GroupPlanSubgroup`
does (`groupPlanId` is required, so a `GroupPlanSubgroup` cannot outlive its plan).

`kind` MUST be additive: existing `Cohort` rows are valid without it (default `teaching` applies), and no
existing `Cohort` consumer (enrolment, attendance, rollover) is required to branch on it.

#### Scenario: A coordinator creates a standing plusklas group

<!-- @e2e exclude a single additive enum property on an already-manifest-declarative schema (Cohort);
     asserted by the register's JSON Schema shape and existing Cohort CRUD e2e coverage, not a new browser
     scenario -->

- **GIVEN** the `Cohort` schema declares `kind`
- **WHEN** a coordinator creates a `Cohort` with `kind: "plusklas"` and no `programmeId`/`courseId`
- **THEN** the cohort is created and persists across academic periods like any other `Cohort`, unlike a
  `GroupPlanSubgroup`, which requires and is scoped to one `GroupPlan`

#### Scenario: An existing Cohort without a declared kind defaults to teaching

- **GIVEN** a `Cohort` row created before this change, with no `kind` value stored
- **WHEN** the row is read
- **THEN** `kind` resolves to its default, `"teaching"`
