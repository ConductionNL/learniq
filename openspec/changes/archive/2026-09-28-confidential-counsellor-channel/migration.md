# Migration: confidential-counsellor-channel

## Current State
Eight declared scopes; no `ConfidentialNote` schema.

## Target State
Nine declared scopes (`confidential-counsellors` added); a new `confidential-note` schema, version `0.1.0`, with one seed row.

## Migration Class
```
Version: none
File: none
Key operations:
- none. The register import repair step (install and post-migration) imports the schema; OpenRegister's group provisioner creates the group from the scope.
```
Learniq owns no tables (ADR-001).

## Migration Steps
1. App upgrade runs the register import.
2. OpenRegister creates the `confidential-note` schema and the `confidential-counsellors` group.
3. An administrator adds the school's vertrouwenspersoon to the group.

## Data Impact
None on existing data. The group starts empty, which denies everyone: correct until a member is added.

## Rollback Procedure
Revert and upgrade. Ask authors to delete or export their notes first; OpenRegister keeps the group (create-only provisioning), with no rule referencing it.

## Validation
- `GET /cloud/groups` lists `confidential-counsellors`.
- A user in `administration-managers` lists `confidential-note` objects and gets none.
- `vendor/bin/phpunit --filter 'ConfidentialCounsellorChannelRegisterTest|DashboardRoleServiceTest'` passes.
