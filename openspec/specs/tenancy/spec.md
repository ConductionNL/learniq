---
status: done
---

# tenancy Specification

## Purpose

Keep one school's or company's learniq records apart from another's on a shared instance. Learniq stamps a `tenant_id` on the rows it writes and scopes the endpoints that take an object id from the caller to the caller's own tenant. This spec describes the code on development as of 7 October 2026: `lib/Service/CallerTenantResolver.php`, its callers (`RolloverController`, `ExternalTrainingController`, `QtiImportController`, `CoursePackageImportController`, `XapiStatementIngest`, `XapiDocumentStore`, `LearningRecordImportIntakeService`, `CourseStoreInstaller`, `AuditPackBuilder`, `OnboardingFolderSetting`, `AccessibilityFeedbackReporterStamp`), `PageController`'s `callerTenant` initial state and `lib/Repair/MoveInstanceIdTenantToDefaultTenant.php`. Administrators bind users as described in `docs/Technical/tenants.md`. It covers capability row `gov-keep-tenants-apart`.

Scope note: the list pages read objects through OpenRegister's object API, and learniq adds no `tenant_id` filter to them. Separation on those pages rests on OpenRegister, not on this spec.

## Requirements

### Requirement: Learniq MUST resolve a user's tenant in one place

`CallerTenantResolver::resolve()` and `forUserId()` SHALL return the user's learniq `tenant_id` user setting when it is set, and the default tenant `00000000-0000-4000-8000-000000000000` otherwise. Every learniq path that stamps or scopes a tenant MUST use this resolver and MUST NOT fall back to the Nextcloud instance id.

#### Scenario: An unbound user lands in the default tenant
<!-- @e2e exclude Backend resolution; covered by PHPUnit tests/Unit/Service/CallerTenantResolverTest.php and DefaultTenantRoutingTest.php. -->

- **GIVEN** a user with no learniq `tenant_id` setting
- **WHEN** learniq resolves the user's tenant
- **THEN** it returns `00000000-0000-4000-8000-000000000000`

#### Scenario: A bound user lands in their own tenant
<!-- @e2e exclude Backend resolution; covered by PHPUnit tests/Unit/Service/CallerTenantResolverTest.php. -->

- **GIVEN** an administrator ran `occ user:setting jdevries learniq tenant_id 6f1c...` for user `jdevries`
- **WHEN** learniq resolves the tenant of `jdevries`
- **THEN** it returns `6f1c...`

### Requirement: Rows created in the app MUST carry the creator's tenant

`PageController` SHALL provide the caller's resolved tenant as the `callerTenant` initial state, and `src/main.js` SHALL hand it to nextcloud-vue's tenant context, so the shared create dialog fills the hidden `tenant_id` field. Server-side writes (xAPI statements and documents, course package and QTI imports, the course store, lesson onboarding, the learning record intake) MUST stamp the resolved tenant as well.

#### Scenario: A record made in the create dialog carries the tenant
<!-- @e2e exclude The hidden field is not visible in the DOM; the stamp is read from the saved object. Covered by PHPUnit DefaultTenantRoutingTest for the server paths. -->

- **GIVEN** a user bound to tenant T
- **WHEN** the user creates a record through the app's create dialog
- **THEN** the saved record's `tenant_id` is T

### Requirement: An endpoint that takes an object id MUST answer only for the caller's tenant

`CallerTenantResolver::owns()` SHALL treat a row as the caller's only when its `tenant_id` equals the caller's tenant, and a row without `tenant_id` as nobody's. `findOwned()` MUST return nothing for an unknown id and for another tenant's id alike, so the endpoint answers 404 in both cases and writes nothing. `RolloverController` (plan preview and mapping proposal) and `ExternalTrainingController` (credential issue and learner coverage) SHALL use it; `proposeMapping()` MUST filter cohorts on the caller's `tenant_id`.

#### Scenario: Another tenant's rollover plan reads as absent
<!-- @e2e exclude Needs two tenants on one instance; covered by PHPUnit tests/Unit/Controller/RolloverControllerCrossTenantTest.php. -->

- **GIVEN** a rollover plan with `tenant_id` T2
- **WHEN** a user of tenant T1 asks for its preview
- **THEN** the answer is 404 "Plan not found"
- **AND** the plan is not changed

#### Scenario: A credential cannot be issued for another tenant's training record
<!-- @e2e exclude Needs two tenants on one instance; covered by PHPUnit tests/Unit/Controller/ExternalTrainingControllerCrossTenantTest.php. -->

- **GIVEN** an external training record with `tenant_id` T2
- **WHEN** a user of tenant T1 asks to issue a credential for it
- **THEN** the answer is 404 and no credential is issued

### Requirement: Rows stamped with the instance id MUST move to the default tenant on upgrade

The repair step `MoveInstanceIdTenantToDefaultTenant` SHALL rewrite the `tenant_id` of every learniq row that carries the Nextcloud instance id to the default tenant, touch no other row, and change nothing when run again.

#### Scenario: An old row is moved once
<!-- @e2e exclude Repair step; covered by PHPUnit tests/Unit/Repair/MoveInstanceIdTenantToDefaultTenantTest.php. -->

- **GIVEN** an xAPI statement whose `tenant_id` is the instance id
- **WHEN** the app is upgraded
- **THEN** its `tenant_id` is `00000000-0000-4000-8000-000000000000`
- **AND** running the repair again changes nothing
