# Migration: segment-example-datasets-corporate

## Current State
`ExternalTrainingRecord` in `lib/Settings/learniq_register.json` carries one `x-openregister-seed` row ("NIS2 board awareness session"). OpenRegister's `ImportHandler` never reads that key (it reads `x-openregister.seedData` and `components.objects`), so the row exists on no instance.

## Target State
The block is an empty array; the row lives, extended and made fictional, in `lib/Settings/profiles/corporate.json`, imported only when an admin picks the company set. Schema version: ExternalTrainingRecord 0.2.0 to 0.2.1. Register `info.version` 0.25.1 to 0.25.2.

## Migration Class
None. No table, column or property changes; the version bump re-imports the register definition through the existing repair path.

## Migration Steps
1. Deploy; the register import sees `info.version` 0.25.2 and updates the schema definition (seed block now empty, no effect on stored objects).

## Data Impact
Zero stored objects change: the retired row was never imported. Safe on live data.

## Rollback Procedure
Revert the PR. If the company set was loaded, remove it first with `occ learniq:example-set:remove corporate --apply`.

## Validation
`vendor/bin/phpunit --filter 'ExampleSetDescriptorContractTest|CorporateExampleSetTest'`.
