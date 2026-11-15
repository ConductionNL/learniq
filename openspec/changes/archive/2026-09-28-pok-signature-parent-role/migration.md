# Migration: pok-signature-parent-role

## Current State

- `PokSignature.signerRole` enum: `student`, `school`, `praktijkopleider` (schema 0.1.0).
- `Praktijkovereenkomst` has no parent flag; `isFullySigned` counts three roles (schema 0.1.0).

## Target State

- `PokSignature.signerRole` adds `parent` (0.2.0).
- `Praktijkovereenkomst` adds `parentSignatureRequired` (boolean, default false), the `parentSignatureCount` aggregate, the parent clause in `isFullySigned`, and the stamp action on `requestSignatures` and `activate` (0.2.0).
- Register `info.version` bumped one minor.

## Migration Class

None. The register import on app upgrade applies the schema changes. No table or column is added.

## Migration Steps

1. `occ upgrade` imports the register.
2. Existing POKs keep no flag (read as false) until their next `requestSignatures` or `activate`. The guard does not depend on the flag, so a POK waiting in `pending-signatures` is judged by the new rule on activation.

## Data Impact

Additive. No row is rewritten. A minor's POK already `pending-signatures` now needs a listed parent's signature before it activates; `active` and `completed` POKs are untouched.

## Rollback Procedure

Re-import the previous register. `parent` signatures are append-only and never re-saved, so they stay readable.

## Validation

- The `pok-signature` schema lists `parent` in `signerRole`.
- `requestSignatures` on a 16-year-old's POK leaves `parentSignatureRequired: true`.
- `activate` on that POK is refused until a parent from the profile's `parentIds` signs.
