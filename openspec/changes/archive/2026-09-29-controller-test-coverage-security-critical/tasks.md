## 1. ActionMatrixController

- [x] 1.1 Create `tests/Unit/Controller/ActionMatrixControllerTest.php`. Mock `ActionAuthService` and
      `IGroupManager`; assert `getMatrix()` (`lib/Controller/ActionMatrixController.php:78`) returns the
      seeded defaults from `SEED_PATH` (`lib/actions.seed.json`) when no override is stored.
- [x] 1.2 Assert `setMatrix()` (line 117) persists a valid matrix payload and a subsequent `getMatrix()`
      call round-trips it.
- [x] 1.3 Assert `setMatrix()` rejects a malformed payload (missing action key / non-array value) with a
      4xx `JSONResponse` and leaves the previously-stored matrix unchanged.

## 2. KeyAdminController

- [x] 2.1 Create `tests/Unit/Controller/KeyAdminControllerTest.php`. Mock `KeyManagementService`,
      `IAppConfig`, `IConfig`, `IUserSession`; assert `generateKey()`
      (`lib/Controller/KeyAdminController.php:103`) with no existing key returns 201 and a body that
      contains no private-key material (only the shape `KeyManagementService::generateTenantKeypair()`
      is documented to return).
- [x] 2.2 Assert `generateKey()` on an existing key without `confirm=true` returns 400 and does NOT call
      `generateTenantKeypair()` (rotation-confirmation gate at line 126-133).
- [x] 2.3 Assert `generateKey()` within the throttle window (line 136-141) returns 429 and does not
      rotate.
- [x] 2.4 Assert `generateKey()` with a `tenantId` that does not match the caller's server-resolved bound
      tenant (line 114-121) returns 403 and does not call the key-management service.
- [x] 2.5 Assert `keyStatus()` (line 175) returns `{configured: false}` shape when no key exists and the
      fingerprint/publicKey shape when one does, without ever including private-key material.

## 3. AuditPackExportController

- [x] 3.1 Create `tests/Unit/Controller/AuditPackExportControllerTest.php`. Mock `IUserSession`, `IConfig`,
      `objectService`, `auditTrailMapper`; assert `export()`
      (`lib/Controller/AuditPackExportController.php`, per its own docblock lines 33-55) scopes every
      query it issues (`auditTrailMapper->findAll`, `objectService->findAll`) to the caller's resolved
      `tenant_id`, using the same assertion style as
      `openspec/changes/fix-cross-tenant-idor-planid-lookups/tasks.md` tasks 1.3/1.4/2.3/2.4 (two tenants,
      assert only the caller's own tenant's data is present in the produced ZIP).
- [x] 3.2 Assert a date range with no matching entries produces a ZIP containing header-only CSVs (not an
      error response) — covering the `empty($rows) === true` branch at line ~540.
- [x] 3.3 Assert `buildExternalTrainingCsv()`'s output (line 519 signature) is exercised through a real
      `export()` call end-to-end, not only indirectly via `ExternalTrainingServiceTest`.

## 4. Traceability and quality gates

- [x] 4.1 Add `@spec openspec/changes/controller-test-coverage-security-critical/tasks.md#task-N` docblock
      tags to the three new test files, matching the app's existing `@spec` convention.
- [x] 4.2 Run `composer check:strict` (PHPCS/PHPMD/Psalm/PHPStan) on the three new test files and fix any
      pre-existing warnings encountered in them (per CLAUDE.md).
- [x] 4.3 Run `openspec validate controller-test-coverage-security-critical --strict` and resolve any
      errors.

## 5. Deferred (tracked, not implemented here)

- [x] 5.1 File a follow-up GitHub issue for controller-level tests on `ExternalTrainingController`,
      `PageController`, `QtiImportController` (also zero-coverage at HEAD, per the proposal's evidence).

## Evidence (round 5, lane r5-security)

- 1.1 to 1.3: `tests/Unit/Controller/ActionMatrixControllerTest.php`. 1.3 was red on development: a PUT with
  no `matrix` answered 200 and wrote an empty matrix, wiping every grant (the controller coerced a
  non-array to `[]`). `ActionMatrixController::setMatrix()` now refuses with 400, before any write, a
  matrix that is missing, not a map, has a non-list value or a non-string group, or omits a seeded
  action. The admin screen always submits every seeded action, so its saves are unaffected.
- 2.1 to 2.5: `tests/Unit/Controller/KeyAdminControllerTest.php`, over the real `KeyManagementService`
  and a real RSA key (app config and ICrypto in memory); every response is checked for `PRIVATE KEY`
  and for the encrypted private key. No production change.
- 3.1 to 3.3: `tests/Unit/Controller/AuditPackExportControllerTest.php`, through the real
  `AuditPackBuilder`, `ExternalTrainingCsvBuilder` and `VerwerkingsregisterCsvBuilder`, opening the
  returned ZIP. The audit-trail and external-training queries carry the caller's `tenant_id`; the
  verwerkingsregister is scoped by OpenRegister's own RBAC, so the test asserts the caller's session
  (not a service identity) is forwarded. `buildExternalTrainingCsv()` from the proposal now lives in
  `ExternalTrainingCsvBuilder::build()`. `DataDownloadResponse` needs Symfony's `HeaderUtils`, which
  only the server ships: a self-guarding stub at `tests/Stubs/Symfony/HeaderUtils.php` is loaded by
  both bootstraps when the real class is absent.
- 4.1: `@spec ...#task-N` on each new test file. 4.2: full `composer check:strict` in the PR body.
  4.3: `openspec validate --strict` clean.
- 5.1: already filed as ConductionNL/learniq#564. Since then `ExternalTrainingController` and
  `PageController` gained test files; `QtiImportController` still has none.
