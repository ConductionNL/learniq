## 1. RolloverController — tenant scoping

- [x] 1.1 In `proposeMapping()` (`lib/Controller/RolloverController.php`), resolve the caller's tenant using
      the same pattern as `AuditPackExportController::export()` (`getSystemValue('instanceid', ...)` +
      `getUserValue($user->getUID(), 'scholiq', 'tenant_id', '')` override), and add `tenant_id` to the
      `cohort` findAll filters (currently only `academicYear`).
- [x] 1.2 In `preview()`, resolve the caller's tenant the same way, fetch the `rollover-plan` by `$planId`,
      and return `404 Plan not found` when `$plan['tenant_id']` does not equal the caller's tenant — before
      calling `rolloverService->preview()` or `saveObject()`.
- [x] 1.3 Unit test: `proposeMapping` with two tenants sharing the same `academicYear` value returns only
      the caller's own tenant's cohorts.
- [x] 1.4 Unit/integration test: `preview` with a `planId` belonging to another tenant returns 404 and does
      not mutate the plan's `lifecycle` or `dryRunReport`.

## 2. ExternalTrainingController — tenant scoping

- [x] 2.1 In `issueCredential()` (`lib/Controller/ExternalTrainingController.php`), resolve the caller's
      tenant (same pattern) and return `404 Record not found` when the fetched
      `external-training-record`'s `tenant_id` does not match — before the `verified` lifecycle check or
      any Credential build/save.
- [x] 2.2 In `learnerCoverage()`, resolve the target learner's tenant via the `learner-profile` schema and
      return `404` (or the non-leaking default `{covered: false, evidenceClass: null}` per the existing
      "no arbitrary-object exposure" intent in the method's docblock) when it does not match the caller's
      tenant.
- [x] 2.3 Unit test: `issueCredential` with a `recordId` belonging to another tenant returns 404 and issues
      no Credential.
- [x] 2.4 Unit test: `learnerCoverage` with a `learnerId` belonging to another tenant does not return the
      real coverage/evidence class.

## 3. Traceability

- [x] 3.1 Add `@spec openspec/changes/fix-cross-tenant-idor-planid-lookups/tasks.md#task-1` /
      `#task-2` docblock tags to the four modified methods (matching the app's existing `@spec` convention).
- [x] 3.2 Run `composer check:strict` (PHPCS/PHPMD/Psalm/PHPStan) on the four touched files and fix any
      pre-existing warnings encountered in them (per CLAUDE.md).
- [x] 3.3 Run `openspec validate fix-cross-tenant-idor-planid-lookups --strict` and resolve any errors.

## Evidence (round 5, lane r5-security)

- 1.1, 1.2, 2.1, 2.2: the caller's tenant comes from the new `lib/Service/CallerTenantResolver.php`
  (same per-user `tenant_id` then `instanceid` resolution as `AuditPackBuilder`/`QtiImportController`).
  `proposeMapping()` adds `tenant_id` to the cohort filters; `preview()`, `issueCredential()` and
  `learnerCoverage()` go through `CallerTenantResolver::findOwned()`, which returns null for an unknown
  id and for another tenant's id alike, so both answer 404 (coverage: `{covered: false, evidenceClass: null}`).
  A row without a `tenant_id` is never owned.
- 1.3, 1.4: `tests/Unit/Controller/RolloverControllerCrossTenantTest.php` (written first, red on
  development, green after the fix), with a same-tenant control.
- 2.3, 2.4: `tests/Unit/Controller/ExternalTrainingControllerCrossTenantTest.php` (written first, red on
  development, green after the fix), with a same-tenant control and an unknown-learner case.
- 3.1: `@spec ...#task-1` / `#task-2` on the four methods and on the resolver.
- 3.2: phpcs, phpmd and phpstan clean on the three touched lib files; full `composer check:strict` in the PR body.
