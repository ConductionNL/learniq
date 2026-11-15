# Migration: assessment-portal-endpoints

## Current State
`AssessmentResult` (0.1.0) has no portal scope key and no copy of the test's title.

## Target State
`AssessmentResult` (0.2.0) declares `learnerRef` (nullable uuid) and `assessmentTitle` (nullable
string), both `readOnly` and stamped by the attempt gate on every create.

## Migration Class
None. OpenRegister re-imports the register when `info.version` moves.
```
Version: n/a (register re-import)
File: lib/Settings/learniq_register.json
Key operations:
- add AssessmentResult.properties.learnerRef, assessmentTitle
- AssessmentResult.version 0.1.0 -> 0.2.0, info.version bumped
```

## Migration Steps
1. `occ upgrade` re-imports the register; OpenRegister adds the two columns.

## Data Impact
No row is transformed. Existing attempts keep both fields null and do not appear in the portal's
attempts list; `available` still finds an attempt in progress by `learnerId`.

## Rollback Procedure
Revert the register file; the previous schema ignores the two stored values.

## Validation
- `openspec validate assessment-portal-endpoints` passes.
- `vendor/bin/phpunit --filter 'Portal|AssessmentResultPortalStamp|AssessmentAttemptGateListener'` passes.
