# Migration: curriculum-coverage-rollup

## Current State
No coverage object exists.

## Target State
A read-only `CurriculumCoverage` schema (0.1.0) in the `learniq` register, filled by a listener and an occ command.

## Migration Class
```
Version: none
File: none
Key operations:
- none: learniq stores no own tables (ADR-001); the repair step imports the new schema when info.version changes.
```

## Migration Steps
1. Bump `info.version`; the repair step imports `CurriculumCoverage`.
2. Run `occ learniq:curriculum-coverage:recompute` once to fill coverage for data saved before this change.

## Data Impact
Only new, derived rows. No existing object is changed. Safe on live data; the command can run again at any time.

## Rollback Procedure
Revert the diff. Coverage rows can stay (nothing reads them) or be deleted through the objects API.

## Validation
- The five new test classes pass.
- After the command, every framework has at least its total row (`year: null`, `subjectScope: all`).
