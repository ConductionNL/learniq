# Migration: segment-runtime-bridge

## Current State
`LearniqSettings.segment` is a string enum of five values (`po`, `vo`, `mbo`, `he`, `corporate`), default `corporate`, no `x-enum-labels`. Schema version `0.1.0`, register `info.version` `0.24.9`.

## Target State
The enum gains a sixth value, `training`, and an `x-enum-labels` map for all six. Schema version `0.2.0`, register `info.version` `0.25.0`. No property is added, renamed or removed.

## Migration Class
None. The register is re-imported by OpenRegister's `ConfigurationService::importFromApp()` when the version changes (the existing repair path); an enum widening needs no Nextcloud migration class and no magic-table column change, because the column already stores a string.

## Migration Steps
1. Deploy the app update; the register import picks up `info.version` `0.25.0` and updates the `LearniqSettings` schema definition.
2. Nothing else runs. Existing rows keep their value; every existing value is still valid.

## Data Impact
Zero rows are transformed. Existing `LearniqSettings` rows (normally one) keep their stored code. Safe on live data.

## Rollback Procedure
Revert the app. Before reverting, set any row holding `training` to another value, because the five-value enum would reject it on the next save.

## Validation
- `vendor/bin/phpunit --filter SegmentFeatureFlagsRegisterTest` asserts the six values and their labels.
- After deploy, the App settings page offers "Training institute" in the segment dropdown.
