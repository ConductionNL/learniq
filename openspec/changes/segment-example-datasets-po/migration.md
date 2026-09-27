# Migration: segment-example-datasets-po

## Current State
Ten schemas in `lib/Settings/learniq_register.json` carry non-empty `x-openregister-seed` blocks with primary school rows. OpenRegister's `ImportHandler` never reads that key (it reads `x-openregister.seedData` and `components.objects`), so none of these rows exists on any instance.

## Target State
The ten blocks are empty arrays; their rows live, extended and made fictional, in `lib/Settings/profiles/po.json`, imported only when an admin picks the set. Schema versions: School, Vestiging, Cohort, ReportPeriod, GroupPlan, GroupPlanSubgroup, GroupPlanEvaluation, Staff, SubjectTeacherAssignment 0.1.0 → 0.1.1; Enrolment 0.2.0 → 0.2.1. Register `info.version` 0.25.0 → 0.25.1.

## Migration Class
None. No table, column or property changes; the version bump re-imports the register definition through the existing repair path.

## Migration Steps
1. Deploy; the register import sees `info.version` 0.25.1 and updates the ten schema definitions (seed block now empty, no effect on stored objects).

## Data Impact
Zero stored objects change: the retired rows were never imported. Safe on live data.

## Rollback Procedure
Revert the PR. If the po set was loaded, remove it first with `occ learniq:example-set:remove po --apply`.

## Validation
`vendor/bin/phpunit --filter 'ExampleSetDescriptorContractTest|PrimarySchoolExampleSetTest|SchoolAndLocationRegisterTest|EnrolmentStatutoryFieldsRegisterTest|SchoolYearShapeRegisterTest|GroepsplanRegisterTest|SubjectAndTeacherAssignmentRegisterTest|CohortGroupPagePolishRegisterTest'`.
