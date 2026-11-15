# Migration: care-and-support-index

## Current State
`LearningPlan` has no deadline tracking or uitstroombestemming fields. No `ObservationInstrument`
or `Trajectory`/`TrajectoryStatusUpdate` schema exists. `LearningPlans` is the only index over the
`learning-plan` schema.

## Target State
`LearningPlan` gains 5 nullable additive properties plus a materialised calculation and a declared
notification. Three new schemas exist: `ObservationInstrument`, `Trajectory`,
`TrajectoryStatusUpdate`. `LearningPlans` gains care-team-relevant columns and a
`CareTeamOverviewMenu` query-preset deep-link exists alongside the
unchanged `LearningPlans` index.

## Migration Class
Not applicable — no Doctrine tables (this app owns none). OpenRegister's own schema-apply step
provisions the three new object tables and reconciles the new nullable columns on `LearningPlan` on
next app enable/upgrade.

## Migration Steps
1. Merge the register JSON patch (new properties/calculation/notification on `LearningPlan`; three
   new schema blocks).
2. On next app enable/upgrade, OpenRegister provisions the new schemas' storage and adds the new
   nullable columns to `LearningPlan` (no-op for every existing row).
3. Seed data loads on fresh install only (existing `DemoDataService` convention).

## Data Impact
Zero rows change value on existing `LearningPlan` objects. No data loss, no transformation. Safe on
a live, populated instance.

## Rollback Procedure
Revert the register JSON patch. Any `ObservationInstrument`/`Trajectory`/`TrajectoryStatusUpdate`
objects already created become orphaned register data (same rollback shape as any other schema
removal in this register); removing the five new nullable `LearningPlan` properties is non-breaking
since every existing row leaves them `null`.

## Validation
- `python3 -m json.tool lib/Settings/learniq_register.json` (well-formed JSON).
- `tests/Unit/Settings/CareAndSupportIndexRegisterTest.php` asserts the new schema blocks, the
  `isOverdueForActivation` calculation shape, the `Trajectory` lifecycle transition table, and
  `TrajectoryStatusUpdate.appendOnly`.
