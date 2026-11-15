# Tasks: confidential-counsellor-channel

## Implementation Tasks

### Task 1: Declare the scope and the ConfidentialNote schema
- **spec_ref**: `openspec/changes/confidential-counsellor-channel/specs/confidential-counsel/spec.md#requirement-the-confidential-counsellor-has-one-declared-scope`, `#requirement-confidential-notes-are-readable-by-their-author-and-named-participants-only`, `#requirement-confidential-notes-are-structurally-isolated`
- **files**: `lib/Settings/learniq_register.json` (scope, schema, authorization, seed row, `info.version`), `lib/Settings/learniq_mock_register.json` (demo rows)
- **acceptance_criteria**:
  - GIVEN the scopes WHEN read THEN `confidential-counsellors` is declared
  - GIVEN `ConfidentialNote.authorization` WHEN read THEN it matches the spec exactly and names no other group
  - GIVEN the register WHEN scanned THEN no `$ref` points to or from `ConfidentialNote`, it is not searchable, it hard-deletes and it is not append-only
  - GIVEN `tests/Unit/Register/` WHEN run THEN no failure names `ConfidentialNote`
- [x] Implement
- [x] Test

### Task 2: Teach the role resolver and the page shell
- **spec_ref**: `openspec/changes/confidential-counsellor-channel/specs/confidential-counsel/spec.md#requirement-the-confidential-counsellor-has-one-declared-scope`, `#requirement-the-confidential-notes-menu-is-shown-to-confidential-counsellors-only`
- **files**: `lib/Service/DashboardRoleService.php`, `lib/Controller/PageController.php`, `src/main.js`, `tests/Unit/Service/DashboardRoleServiceTest.php`
- **acceptance_criteria**:
  - GIVEN a user in `instructors` and `confidential-counsellors` WHEN resolved THEN `instructor`, and `isConfidentialCounsellor()` is true
  - GIVEN a user only in `confidential-counsellors` WHEN resolved THEN `confidential-counsellor` with views `['student']`
  - GIVEN the SPA boots WHEN initial state is read THEN `runtime.user.isConfidentialCounsellor` is set
- [x] Implement
- [x] Test

### Task 3: Menu entry and pages
- **spec_ref**: `openspec/changes/confidential-counsellor-channel/specs/confidential-counsel/spec.md#requirement-the-confidential-notes-menu-is-shown-to-confidential-counsellors-only`
- **files**: `src/manifest.d/confidential-counsel.json`, `l10n/en.json`, `l10n/nl.json`, `docs/user-guide/admin/03-admin-settings.md`
- **acceptance_criteria**:
  - GIVEN the manifest WHEN validated THEN `check:manifest` and `check:menu-role-gates` pass
  - GIVEN every new label WHEN looked up THEN it has an English key and a Dutch value
- [x] Implement
- [x] Test

### Task 4: Register test
- **spec_ref**: all requirements above
- **files**: `tests/Unit/Settings/ConfidentialCounsellorChannelRegisterTest.php` (new)
- **acceptance_criteria**:
  - GIVEN the test WHEN run THEN it pins the scope, the block, the forbidden groups, isolation, flags, the seed row and the menu gate
- [x] Implement
- [x] Test

## Quality checklist
- PHPUnit covers the resolver change and the register shape.
- No new endpoint; `PageController::index()` only adds initial state.
- The two pages are standard manifest pages; no custom Vue.
- Dutch and English strings for every new label.
- `openspec validate confidential-counsellor-channel` passes.
