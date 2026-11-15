# Design: retired-schemas-prune

## Architecture overview

```
<post-migration>
  MigrateDataSubjectRequestsToOpenRegister   copies data-subject-request rows to OR's DSR register (#1070)
  ArchiveRetiredPaymentObjects               writes order/order-line/payment-transaction rows to retired-payments.json (#1082)
  PruneRetiredSchemas                        NEW: per slug, per (application, slug) row:
                                               archival?            -> leave, report
                                               empty, or payment rows all archived? -> unlink, cascadeDeleteSchema
                                               otherwise            -> leave, report, retry next upgrade
  InitializeSettings                         imports the register (which no longer lists the four)
```

## How OpenRegister retires a schema

There is no "retired" flag on a schema. OpenRegister's answer is the explicit operator command `openregister:schemas:prune-retired --app <app> --slug <slug> [--apply] [--force] [--force-archival]` (`lib/Command/PruneRetiredSchemasCommand.php`, spec `schema-import`, requirement "A schema retired from a descriptor MUST be removable from the instance"). Its mechanics:

1. `SchemaMapper::findAllByApplicationAndSlug($slug, $app)`: every row, not the first (the pair is not unique in practice).
2. `SchemaDeletionService::countObjectsCascadeWouldDelete($schema)`: refuse when > 0 unless `--force`.
3. `Schema::hasArchivalAnnotation()`: refuse unless `--force-archival`.
4. Unlink the id from every register (`RegisterMapper::findAll(_rbac: false, _multitenancy: false)`, int and numeric-string forms), then `RegisterMapper::update()`.
5. `SchemaDeletionService::cascadeDeleteSchema($schema)`: rows, table, schema row.

## Decisions

### D1. Borrow the command's mechanics in a repair step
Running the command itself from PHP would parse console output and take one `--force` for all slugs. The steps are small and public, and hermiq's `PruneRetiredAgentFlowSchemas` (#743) set the fleet pattern for exactly this. The OpenRegister services are resolved lazily from the container, so an instance without them skips the step instead of failing the upgrade.

### D2. `--force` is replaced by evidence for payments, and by the school for privacy requests
The command's operator decides to drop rows. For the payment schemas the evidence decides: `ArchiveRetiredPaymentObjects::isFullyArchived()` answers true only when every current row id is in `retired-payments.json` and the rows read match the count the deletion would remove. That archive exists so the schemas can go (its docblock says so). For `data-subject-request`, `privacy-reuse-openregister-register` decided that deleting an AVG record in a repair step is the wrong default and that a school drops the source rows after checking. So the step prunes that schema only when it is empty, and otherwise prints the exact command. Archival schemas are never forced.

### D3. Both application ids
An import stamps `application` only on schemas it imports. A schema retired before an instance's first import as `learniq` still carries `scholiq`. No app owns schemas under `scholiq` any more, so the scoping rule of the command still holds.

### D4. Order
After the two steps that read the rows, before `InitializeSettings`. The import does not re-add the pruned schemas, since the register no longer lists them.

## Declarative-vs-imperative decision

| Behaviour | Path | Rationale |
|---|---|---|
| Removing retired schemas on upgrade | imperative repair step (ADR-031 exception: install/upgrade work, ADR-106) | OpenRegister's import never removes a schema; its retirement path is an explicit operation |

## Security considerations

Destructive only for rows proven kept elsewhere; scoped by application; archival records untouched. Runs without a session, as every repair step does.

## Seed data

No schema is added. None of the four slugs is in the register or the example sets any more.

## Trade-offs

Evidence-gated pruning can leave a schema in place for a long time on an instance whose archive could not be written. That is the safe failure: the rows stay readable in OpenRegister, and the upgrade output names the schema.
