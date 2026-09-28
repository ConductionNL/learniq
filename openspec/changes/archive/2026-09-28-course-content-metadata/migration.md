# Migration: course-content-metadata

## Current State
`Course` (version 0.3.0) and `Lesson` (version 0.3.0) have no licence, author, subject or NL-LOM level fields.

## Target State
Both schemas at version 0.4.0 with four optional properties: `license`, `author`, `subject`, `educationalLevels`.

## Migration Class
```
Version: none
File: none
Key operations:
- none. The register import repair step updates both schema definitions.
```
Learniq owns no tables (ADR-001).

## Migration Steps
1. App upgrade runs the register import.
2. OpenRegister updates `course` and `lesson` to version 0.4.0.

## Data Impact
None. The properties are optional with no default; no row is rewritten.

## Rollback Procedure
Revert and upgrade. Stored values become unknown properties.

## Validation
- Both schemas report version 0.4.0 in OpenRegister.
- An existing course saves unchanged.
- `vendor/bin/phpunit --filter CourseContentMetadataRegisterTest` passes.
