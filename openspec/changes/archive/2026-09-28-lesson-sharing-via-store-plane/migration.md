# Migration: lesson-sharing-via-store-plane

## Current State
No `SharedCoursePackage` schema; no store routes in learniq; the manifest store block declares federated configuration-set types that no route serves.

## Target State
New `shared-course-package` schema (0.1.0) with one seed row; three store routes served by learniq's `StoreController`; the manifest store block describes the course store.

## Migration Class
```
Version: none
File: none
Key operations:
- none. The register import repair step imports the schema.
```

## Migration Steps
1. App upgrade imports the register.
2. An administrator sets the registry: `occ config:app:set learniq registry_url --value=<url>`, `occ config:app:set learniq registry_token --value=<token> --sensitive`, optionally `registry_register`.

## Data Impact
None on existing data.

## Rollback Procedure
Revert and upgrade; the schema stays declared, unused.

## Validation
- `GET /apps/learniq/api/store/items` answers `not_configured` before a registry is set, and cards after.
- `vendor/bin/phpunit --filter 'Store|CourseStore|SharedCoursePackage|LearniqJsonCourseImporter'` passes.
