# Migration: assignment-portal-wiring

## Current State
`Submission` (0.2.0) requires `assignmentId`, `learnerIds` and `tenant_id`. It has an optional array
`learnerRefs` that nothing writes, so no Submission is visible in the portal.

## Target State
`Submission` (0.3.0) requires `assignmentId` only. It declares a nullable scalar `learnerRef`
(LearnerProfile UUID). `learnerIds` and `tenant_id` are still required on every write, enforced by
`SubmissionOwnerStamp` after it stamps a portal hand-in.

## Migration Class
None. OpenRegister re-imports the register when `info.version` moves; no table or column is owned by
learniq.
```
Version: n/a (register re-import)
File: lib/Settings/learniq_register.json
Key operations:
- add Submission.properties.learnerRef
- Submission.required: [assignmentId]
- Submission.version 0.2.0 -> 0.3.0, info.version bumped
```

## Migration Steps
1. `occ upgrade` (or the app update) re-imports the register because `info.version` moved.
2. OpenRegister adds the `learnerRef` column to the Submission table on import.

## Data Impact
No row is transformed. Existing Submissions keep `learnerRef` null until their next write, when the
stamp derives it from `learnerIds[0]`. They stay out of the portal until then, as they are today.

## Rollback Procedure
Revert the register file. The next import restores the 0.2.0 schema; a stored `learnerRef` value is
ignored by the old schema.

## Validation
- `openspec validate assignment-portal-wiring` passes.
- `vendor/bin/phpunit --filter 'SubmissionOwnerStampTest|PortalContributionProviderTest'` passes.
- On an instance: a portal hand-in stores `learnerIds`, `learnerRefs`, `tenant_id` and `learnerRef`.
