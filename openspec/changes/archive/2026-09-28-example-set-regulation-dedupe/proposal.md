---
kind: code
depends_on:
  - example-set-regulation-rows
---

# Proposal: example-set-regulation-dedupe

## Summary
Loading both the company set and the training set creates two VCA rows and two NIS2 rows. Each set ships those regulations under its own uuid, and the importer matches by uuid. With this change, the set loaded second leaves out a regulation whose code already exists under another uuid, so its second load is a no-op for those rows.

## Motivation
- TRACKER-R2 "ROUND 3 FINAL" follow-up: "VCA and NIS2 duplicate when company and training sets load together". Raised in #1137 (example-set-regulation-rows, D29).
- Regulation carries its identity in its code (`slug`, `^[A-Z0-9_-]+$`, unique). Two rows with the code VCA break every lookup that goes by `regulationSlug`.

## Affected Projects
- [x] Project: `learniq`: `SharedCodeFilter` (new), `SeedProfileService::install()`, `docs/installation.md`, tests.

## Scope

### In Scope
- Before a shipped set is imported, rows in the `regulation` bucket whose code exists in the learniq register under a different uuid are left out.
- A row that exists under its own uuid is kept, so reloading the same set still updates it.
- A failed read of the existing rows keeps every row: the filter can make a second load cleaner, but it can never make a load fail.

### Out of Scope
- A shared fixed uuid for both sets. The two sets define the regulations differently: the company obliges its Operatie department and its board, while the training institute obliges nobody. One shared object would force one of them to carry the other's audience.
- Removing the first set while the second still points at its rows by code. A removal soft-deletes what the first set created, and the trash can restore it.

## Approach
Code: one small service that reads the existing regulation codes through OpenRegister's object service (resolved by name, like the importer), and one call in `install()`.

## New Dependencies
None.

## Impact
- The first set's definition of VCA and NIS2 is the one that stays when both are loaded. The second set's courses and certificates point at the regulation by code, so they find it.
