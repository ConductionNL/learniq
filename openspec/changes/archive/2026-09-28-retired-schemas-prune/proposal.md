---
kind: code
depends_on: []
---

# Proposal: retired-schemas-prune

## Summary

Today learniq retired four schemas from its register: `order`, `order-line` and `payment-transaction` (D19, #1082) and `data-subject-request` (D20, #1070). On an instance that imported them before, they stay: OpenRegister's import unions schema ids into the register and never removes one. This change adds a repair step that removes them through OpenRegister's own retirement path, the mechanics of `occ openregister:schemas:prune-retired`. A payment schema goes with its rows once every row is in #1082's payments archive. The privacy request schema goes only when it is empty, because #1070 decided a repair step does not delete AVG records. The step is idempotent and never deletes a row that exists only here.

## Motivation

OpenRegister's schema-import spec says it outright ("A schema retired from a descriptor MUST be removable from the instance"): dropping a schema from a descriptor leaves its row, its magic table and its place in the register's `schemas` array. For learniq that means an instance upgraded past #1070 and #1082 still lists four schemas nothing reads or writes, with rows that show up in OpenRegister's own object views and exports, and a slug claim (`order`, `payment-transaction`) that can collide with shillinq's on a shared instance.

OpenRegister ships the answer as an operator command, `openregister:schemas:prune-retired --app <app> --slug <slug>` (`PruneRetiredSchemasCommand`, openregister `origin/development` `ae898b06`). It is explicit by design, so no operator will run it for learniq's four slugs unprompted. hermiq solved the same problem in a repair step (`PruneRetiredAgentFlowSchemas`, hermiq #743) that borrows the command's mechanics.

## Affected Projects

- [x] Project: `learniq` — one repair step, one public read method on the payments archive step, `appinfo/info.xml`, OpenRegister test stubs, `psalm.xml`

## Scope

### In Scope

- `lib/Repair/PruneRetiredSchemas.php`, after `ArchiveRetiredPaymentObjects` in `<post-migration>`:
  - resolves every row under (application `learniq` or `scholiq`, slug), as the command does with `findAllByApplicationAndSlug()`;
  - skips a schema that declares `x-openregister-archival`;
  - prunes a payment schema with rows only when every current row id is in `retired-payments.json`, and `data-subject-request` only when it owns no rows; a non-empty one is reported with the exact `occ` command a school runs after checking the copies;
  - unlinks the id from every register (the command's coercion rule), then `SchemaDeletionService::cascadeDeleteSchema()`;
  - reports and retries on the next upgrade whatever it leaves; never raises.
- `ArchiveRetiredPaymentObjects::isFullyArchived()`: a read-only answer that fails closed.
- Stubs for `SchemaMapper`, `RegisterMapper` and `SchemaDeletionService` under `tests/Stubs`, mirroring OpenRegister's signatures.

### Out of Scope

- Forcing archival records (`--force-archival`): never.
- Changing OpenRegister. The command and the deletion service exist.
- Pruning any other schema. The list is the four retired today.

## Approach

Reuse OpenRegister's retirement mechanics from a repair step, as hermiq does, and gate the destructive part on the evidence the earlier two steps produce.

## New Dependencies

None. OpenRegister's `SchemaMapper`, `RegisterMapper` and `SchemaDeletionService` are resolved lazily from the container, so an instance without them skips the step.

## Impact

`lib/Repair/PruneRetiredSchemas.php` (new), `lib/Repair/ArchiveRetiredPaymentObjects.php`, `appinfo/info.xml`, `tests/Stubs/Db/{Schema,Register,SchemaMapper,RegisterMapper}.php`, `tests/Stubs/Service/SchemaDeletionService.php`, `psalm.xml`, tests.

## Cross-Project Dependencies

OpenRegister `PruneRetiredSchemasCommand` and `SchemaDeletionService` (present on openregister `development`).

## Risks

### Risk 1: A row that exists only here is deleted
**Severity:** High — **Mitigation:** a payment schema with rows is pruned only when every current row id is in the archive and the number of rows read matches the number the deletion would remove; any read failure answers "not archived". Privacy requests are never deleted by the step.

### Risk 2: The step blocks an upgrade
**Severity:** Medium — **Mitigation:** every failure is caught, logged and reported; the step retries on the next upgrade.

### Risk 3: A schema another app owns is reached
**Severity:** Low — **Mitigation:** resolution is by application id (`learniq`, `scholiq`), as the command's scoping rule requires.

## Rollback Strategy

Revert the merge commit. A pruned schema is not restored: it was empty, or its rows are in the payments archive, which is the condition for pruning.
