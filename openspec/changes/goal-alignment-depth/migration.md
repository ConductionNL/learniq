# Migration: goal-alignment-depth

## Current State
`Lesson`, `Course`, `Assignment` (each 0.3.0) and `Assessment` (0.2.0) carry `competencyIds` only.

## Target State
Each also declares `competencyAlignments` (array of `{competencyId, depth}`, default `[]`). Versions: `Lesson`,
`Course`, `Assignment` 0.4.0; `Assessment` 0.3.0.

## Migration Class
```
Version: none
File: none
Key operations:
- none: learniq stores no own tables (ADR-001); the repair step re-imports the register when info.version changes.
```

## Migration Steps
1. Bump the four schema versions and `info.version`.
2. OpenRegister updates the stored schema definitions. No object is rewritten.

## Data Impact
None at deploy. Existing rows have no alignments, so the listener leaves them alone and readers treat their
`competencyIds` as alignments without depth. Safe on live data.

## Rollback Procedure
Revert the diff and re-run the repair step. `competencyIds` stays filled on every row, so readers keep working.

## Validation
- `vendor/bin/phpunit --filter 'GoalAlignmentDepthRegisterTest|CompetencyAlignmentListenerTest|CompetencyAlignmentNormaliserTest'` passes.
- The gate-101 checker reports the four schemas' demo data valid.
