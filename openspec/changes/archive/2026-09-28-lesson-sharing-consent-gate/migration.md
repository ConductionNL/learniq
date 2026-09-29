# Migration: lesson-sharing-consent-gate

## Current State
No `CourseShareConsent` schema; no `course-package.share` action.

## Target State
New `course-share-consent` schema (0.1.0) with one seed row; action `course-package.share` in the seed matrix (admin).

## Migration Class
```
Version: none
File: none
Key operations:
- none. The register import repair step imports the schema. The action matrix repair seeds new installs; on an existing install an action missing from the matrix is admin-only (GenericActionAuthService fails closed), which is the seeded default anyway.
```

## Migration Steps
1. App upgrade imports the register.
2. An administrator broadens `course-package.share` in Admin settings if teachers should share.

## Data Impact
None on existing data.

## Rollback Procedure
Revert and upgrade. Consent rows stay as records.

## Validation
- `course-share-consent` exists in OpenRegister.
- `vendor/bin/phpunit --filter 'CourseSharing|CourseShare'` passes.
