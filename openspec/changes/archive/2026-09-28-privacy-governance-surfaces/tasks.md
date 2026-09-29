## Implementation Tasks

### Task 1: Add the `Compliance` singleton schema
- **spec_ref**: `openspec/changes/privacy-governance-surfaces/specs/avg-verwerkingsregister/spec.md#requirement-the-school-records-its-privacyconvenant-agreement-and-privacybijsluiter`
- **files**: `lib/Settings/learniq_register.json`
- **acceptance_criteria**:
  - GIVEN the register WHEN `Compliance` is read THEN it carries `privacyconvenantSigned`, `privacyconvenantSignedAt`, `verwerkersovereenkomstUrl`, `privacybijsluiterUrl`, `privacybijsluiterVersion`, `lastReviewedAt`, `lastReviewedBy`, all with title+description
  - GIVEN the schema THEN `authorization.create`/`update` are `["compliance-officers"]` and no lifecycle block exists
- [x] Implement
- [x] Test

### Task 2: Add the `DataSubjectRequest` schema
- **spec_ref**: `openspec/changes/privacy-governance-surfaces/specs/avg-verwerkingsregister/spec.md#requirement-staff-can-log-and-track-a-correction-or-deletion-request`
- **files**: `lib/Settings/learniq_register.json`
- **acceptance_criteria**:
  - GIVEN the schema THEN `kind` enum is exactly `["correction","deletion"]`, required fields include `kind`, `learnerId`, `submittedBy`, `tenant_id`
  - GIVEN the lifecycle THEN `requested` is initial, transitions are `startReview` (requested→in-review), `complete` (in-review→completed), `reject` (in-review→rejected), none carrying a `requires` guard
  - GIVEN `auditTrail` THEN it defaults to `[]` and its item entries require `recordedBy`, `recordedAt`, `action`
  - GIVEN `authorization.create` THEN it is `["instructors","compliance-officers"]`
- [x] Implement
- [x] Test

### Task 3: Add the partner-approval properties on `DataExchangeJob` and extend the run guard
- **spec_ref**: `openspec/changes/privacy-governance-surfaces/specs/data-exchange/spec.md#requirement-a-dataexchangejob-target-can-require-standing-partner-approval-before-it-runs`
- **files**: `lib/Settings/learniq_register.json`, `lib/Lifecycle/DataExchangeRunGuard.php`, `tests/Unit/Lifecycle/DataExchangeRunGuardTest.php`
- **acceptance_criteria**:
  - GIVEN `DataExchangeJob` THEN it gains `requiresPartnerApproval` (default `false`), `partnerApprovalStatus` (enum `not-required|pending|approved|rejected`, default `not-required`), `partnerApprovedBy`, `partnerApprovedAt`, `dataSharedFields` (array of strings, default `[]`)
  - GIVEN `DataExchangeRunGuard::check()` WHEN `requiresPartnerApproval === true` AND `partnerApprovalStatus !== 'approved'` THEN it returns `false` regardless of target
  - GIVEN the same guard WHEN `requiresPartnerApproval === false` (default) THEN existing OSO/SWV/other-target behaviour is byte-for-byte unchanged (existing `DataExchangeRunGuardTest` cases still pass)
- [x] Implement
- [x] Test

### Task 4: Add `PrivacyGovernanceController` and its route
- **spec_ref**: `openspec/changes/privacy-governance-surfaces/specs/avg-verwerkingsregister/spec.md#requirement-a-board-facing-dashboard-composes-group-2fa-and-integration-approval-state`
- **files**: `lib/Controller/PrivacyGovernanceController.php`, `appinfo/routes.php`, `tests/Unit/Controller/PrivacyGovernanceControllerTest.php`
- **acceptance_criteria**:
  - GIVEN `GET /api/privacy-governance/overview` WHEN called by an authenticated user THEN it returns each `rbac-declare-groups` group id with a member count
  - GIVEN no two-factor registry is available THEN the payload's `twoFactorEnabledCount` is `null`, never `0`
  - GIVEN an anonymous caller THEN the endpoint returns 401
- [x] Implement
- [x] Test

### Task 5: Declare the manifest pages and the Vue surfaces
- **spec_ref**: `openspec/changes/privacy-governance-surfaces/specs/avg-verwerkingsregister/spec.md#requirement-a-board-facing-dashboard-composes-group-2fa-and-integration-approval-state`
- **files**: `src/manifest.d/compliance.json`, `src/views/LearniqSettings.vue`, `src/views/PrivacyGovernanceDashboard.vue`
- **acceptance_criteria**:
  - GIVEN the manifest THEN `DataSubjectRequest` has an `index`+`detail` page pair and `DataExchangeJob` has a partner-approval-filtered index page, both declarative (type `index`/`detail`, no new component)
  - GIVEN the manifest THEN `PrivacyGovernanceDashboard` is a `type: custom` page gated `visibleIf: user.primaryRole in [admin, compliance-officer]`
  - GIVEN `LearniqSettings.vue` THEN a new "Privacy governance" section shows the `Compliance` singleton fields and a recent-`DataSubjectRequest` list, using the existing generic object store (no new endpoint)
- [x] Implement
- [x] Test

## Verification
- [x] All tasks checked off
- [x] `openspec validate privacy-governance-surfaces --strict` passes
- [x] Manual review against acceptance criteria

## Tests (company-wide ADR-009)
- [x] PHPUnit unit tests for new/changed business logic (`tests/Unit/Settings/PrivacyGovernanceRegisterTest.php`, `tests/Unit/Lifecycle/DataExchangeRunGuardTest.php`, `tests/Unit/Controller/PrivacyGovernanceControllerTest.php`)
- [x] N/A — Newman/Postman: only one new GET endpoint, covered by PHPUnit controller test; no Playwright — pages are declarative manifest types or reuse the existing generic settings/object infrastructure

## Documentation (company-wide ADR-010)
- [x] N/A for this change — internal compliance/governance surface, no `docs/` user guide exists for LearniqSettings sections today; the manifest/schema descriptions are the documentation of record

## i18n (company-wide ADR-005)
- [x] New user-facing strings (settings section labels, dashboard labels) added via `t('learniq', ...)`; Dutch strings added to `l10n/nl.json`
