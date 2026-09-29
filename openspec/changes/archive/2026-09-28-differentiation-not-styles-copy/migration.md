# Migration: differentiation-not-styles-copy

## Current State
`GroupPlan`, `GroupPlanSubgroup`, `SupportRequest` and `ExamAccommodation` carry engineering prose in their property descriptions and code-like titles.

## Target State
Plain titles and descriptions, engineering rationale in `x-notes`, a minor version bump per schema.

## Migration Class
```
Version: none
File: none
Key operations:
- none. The register import repair step updates the schema definitions.
```

## Migration Steps
1. App upgrade imports the register.

## Data Impact
None. Only titles, descriptions, `x-notes` and enum labels change; no property, type or enum value changes.

## Rollback Procedure
Revert and upgrade.

## Validation
- `vendor/bin/phpunit --filter SupportNeedsVocabularyTest` passes.
