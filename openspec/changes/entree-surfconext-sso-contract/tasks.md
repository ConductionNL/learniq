# Tasks: entree-surfconext-sso-contract

## 1. Schema: SsoAttributeMapping

- **spec_ref**: `openspec/changes/entree-surfconext-sso-contract/specs/identity-federation/spec.md#requirement-persist-ssoattributemapping`
- **files**: `lib/Settings/learniq_register.json`
- **acceptance_criteria**:
  - GIVEN the register schema catalogue
  - WHEN `SsoAttributeMapping` is added
  - THEN it declares `provider` (enum saml/oidc), `externalAttribute`, `learniqField` (enum eckId/schoolId/
    givenName/familyName/role), `roleValue`, `active`, `tenant_id`, with a draft→active→archived→active
    lifecycle
- [x] Implement
- [x] Test

## 2. Service: SsoAttributeMappingApplier

- **spec_ref**: `openspec/changes/entree-surfconext-sso-contract/specs/identity-federation/spec.md#requirement-apply-attribute-mappings-to-compute-learnerprofile-updates`
- **files**: `lib/Service/SsoAttributeMappingApplier.php`
- **acceptance_criteria**:
  - GIVEN a raw attribute bag and a provider
  - WHEN `apply()` is called
  - THEN it returns a LearnerProfile field-update array built only from active mappings for that provider,
    accumulating every `role` match into a `roles` array rather than overwriting
  - GIVEN no active mapping matches an attribute
  - WHEN `apply()` runs
  - THEN that attribute is silently ignored (fail-closed, no invented field)
- [x] Implement
- [x] Test

## 3. Tests

- **spec_ref**: `openspec/changes/entree-surfconext-sso-contract/specs/identity-federation/spec.md#requirement-apply-attribute-mappings-to-compute-learnerprofile-updates`
- **files**: `tests/Unit/Service/SsoAttributeMappingApplierTest.php`, `tests/Unit/Settings/SsoAttributeMappingRegisterTest.php`
- **acceptance_criteria**:
  - GIVEN the register JSON
  - WHEN it is parsed
  - THEN `SsoAttributeMapping`'s shape and lifecycle hold, and the applier's field-computation and
    role-accumulation logic is covered
- [x] Implement
- [x] Test
