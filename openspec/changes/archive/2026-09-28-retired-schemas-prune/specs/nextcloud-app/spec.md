# Nextcloud app: retired schema pruning delta

## ADDED Requirements

### Requirement: Schemas learniq retired leave the instance once their rows are kept elsewhere

On every upgrade, after the payments archive and the privacy request move have run, learniq MUST remove the schemas it retired from its register (`order`, `order-line`, `payment-transaction`, `data-subject-request`) through OpenRegister's retirement path: every schema row under that slug owned by application `learniq` (or `scholiq`, its name before the rename) is unlinked from every register and then cascade-deleted. A payment schema that owns rows MUST be removed only when every current row id is in the payments archive file; a read that fails, or that returns another number of rows than the schema owns, MUST count as "not archived". `data-subject-request` MUST be removed only when it owns no rows, because its rows are AVG records a school drops itself; the upgrade output MUST then name the `occ openregister:schemas:prune-retired` command that does it. A schema that declares `x-openregister-archival` MUST NOT be removed. The step MUST be idempotent and MUST NOT abort the upgrade: what it leaves is reported and retried on the next upgrade.

#### Scenario: An empty retired schema is removed
@e2e exclude Repair step with no screen; pinned by tests/Unit/Repair/PruneRetiredSchemasTest.php (testAnEmptyRetiredSchemaIsUnlinkedThenDeleted).
- **GIVEN** an instance with learniq's `order-line` schema, owning no rows, listed in the learniq register
- **WHEN** the app is upgraded
- **THEN** the schema id leaves the register's schema list
- **AND** the schema and its table are deleted

#### Scenario: Payment rows are removed only after they are archived
@e2e exclude Repair step with no screen; pinned by tests/Unit/Repair/PruneRetiredSchemasTest.php (testAPaymentSchemaWithUnarchivedRowsStays, testAPaymentSchemaWithArchivedRowsIsPruned).
- **GIVEN** learniq's `order` schema owns three rows
- **AND** the payments archive holds two of them
- **WHEN** the app is upgraded
- **THEN** the schema and its rows stay, and the upgrade output says so

#### Scenario: Privacy requests stay until the school drops them
@e2e exclude Repair step with no screen; pinned by tests/Unit/Repair/PruneRetiredSchemasTest.php (testPrivacyRequestsStayUntilTheSchoolDropsThem, testAnEmptyPrivacyRequestSchemaGoes).
- **GIVEN** learniq's `data-subject-request` schema owns two rows, copied to OpenRegister's register
- **WHEN** the app is upgraded
- **THEN** the schema and its rows stay
- **AND** the upgrade output names `occ openregister:schemas:prune-retired --app learniq --slug data-subject-request --apply --force`

#### Scenario: A second upgrade finds nothing to do
@e2e exclude Repair step with no screen; pinned by tests/Unit/Repair/PruneRetiredSchemasTest.php (testNothingLeftIsANoOp).
- **GIVEN** the four schemas were pruned before
- **WHEN** the app is upgraded again
- **THEN** nothing is unlinked or deleted
