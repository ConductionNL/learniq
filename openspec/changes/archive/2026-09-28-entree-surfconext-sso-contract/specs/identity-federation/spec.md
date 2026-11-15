# Identity Federation — SSO Attribute Mapping Contract

**Spec refs**: new capability (`identity-federation`, previously a thin slice absorbed into `data-exchange`
and explicitly excluded there — see that spec's `replaces_thin_slice_of` header and its "Federated
authentication is out of scope" requirement), findings `13.5`

## Purpose

Federated login (Entree Federatie / SURFconext / eduID) through Nextcloud's own `user_saml`/`user_oidc` apps
is out of `data-exchange`'s scope and stays out of it — this capability does not build an IdP client or a
SAML/OIDC handshake. Once a user is authenticated by those apps, this capability declares which external
SAML attribute or OIDC claim maps to which `LearnerProfile` field (or role), and computes the resulting
field-update set from a raw attribute bag. It does not itself listen for any Nextcloud login event.

## ADDED Requirements

### Requirement: Persist SsoAttributeMapping

The system MUST persist `SsoAttributeMapping` as an OpenRegister object: `provider` (enum `saml | oidc`),
`externalAttribute` (the SAML attribute name or OIDC claim name), `learniqField` (enum `eckId | schoolId |
givenName | familyName | role`), `externalValue` and `roleValue` (both nullable, meaningful only when
`learniqField: role` — `externalValue` is the attribute value to match, `roleValue` is the
`LearnerProfile.roles` enum member it maps to), `active` (default true), `tenant_id`.
Lifecycle MUST mirror `DataMappingProfile`'s own shape: `draft` (initial) → `active` → `archived` →
`active` (reactivate). This schema is the settings surface for SSO attribute configuration — per ADR-024/031
it MUST get the same generic OpenRegister index+detail surface every other schema in this register gets, with
no bespoke PHP CRUD controller and no custom Vue view.

#### Scenario: A role mapping names both the external value and the target role

- **GIVEN** a `SsoAttributeMapping` with `learniqField: role`
- **WHEN** it is created
- **THEN** `externalValue` holds the external attribute value (e.g. `"docent"`) and `roleValue` holds the
  `LearnerProfile.roles` member it maps to (e.g. `instructor`)

<!-- @e2e exclude Pure OpenRegister schema/persistence shape, no DOM surface; verified by
     SsoAttributeMappingRegisterTest::testRoleMappingCarriesRoleValue. -->

#### Scenario: Only active mappings are eligible for application

- **GIVEN** an `SsoAttributeMapping` in `archived` state
- **WHEN** attribute mappings are loaded for application
- **THEN** the archived mapping is excluded

<!-- @e2e exclude Declarative lifecycle/filter shape verified by
     SsoAttributeMappingApplierTest::testArchivedMappingIsIgnored; no DOM surface. -->

### Requirement: Apply attribute mappings to compute LearnerProfile updates

The system MUST provide a pure, side-effect-free service (`SsoAttributeMappingApplier::apply(array
$attributes, string $provider, string $tenantId): array`) that loads active `SsoAttributeMapping` rows for
the given provider and tenant, and returns a `LearnerProfile` field-update array built only from mappings
whose `externalAttribute` is present in the supplied attribute bag. Every `learniqField: role` match MUST
accumulate into a `roles` array rather than overwriting a prior match. The service MUST NOT write to
`LearnerProfile` itself and MUST NOT be wired to any Nextcloud login event in this change — that wiring is an
explicit, named follow-up once `user_saml`/`user_oidc`'s real provisioning event surface can be verified from
a checkout that has them installed.

#### Scenario: Attribute mappings compute a field-update array without writing anything

- **GIVEN** an active `saml` mapping `{externalAttribute: "eckId", learniqField: "eckId"}` and an attribute
  bag `{"eckId": "ABC123"}`
- **WHEN** `apply()` is called
- **THEN** it returns `{"eckId": "ABC123"}` and performs no `LearnerProfile` write

<!-- @e2e exclude Pure function logic verified by PHPUnit
     SsoAttributeMappingApplierTest::testComputesFieldUpdateFromMatchingAttribute; no DOM surface — this
     service is never called from a listener in this change. -->

#### Scenario: Multiple role mappings accumulate into one roles array

- **GIVEN** two active `learniqField: role` mappings whose `externalAttribute`/`externalValue` both match the
  supplied attribute bag
- **WHEN** `apply()` is called
- **THEN** the returned `roles` array contains both mapped role values, not just the last one evaluated

<!-- @e2e exclude Pure function logic verified by PHPUnit
     SsoAttributeMappingApplierTest::testAccumulatesMultipleRoleMatches; no DOM surface. -->

#### Scenario: An attribute with no matching mapping is silently ignored

- **GIVEN** an attribute bag containing a key no active `SsoAttributeMapping` names
- **WHEN** `apply()` is called
- **THEN** that attribute contributes no field to the returned update array, and no error is raised

<!-- @e2e exclude Fail-closed pure function logic verified by PHPUnit
     SsoAttributeMappingApplierTest::testUnmappedAttributeIsIgnored; no DOM surface. -->

## Out of Scope

- Any `user_saml`/`user_oidc` event listener or actual login-time wiring (named follow-up, see Why).
- Any IdP client, SAML/OIDC handshake, or OpenConnector-adjacent wire protocol.
- `data-exchange`'s own "Federated authentication is out of scope" requirement is unchanged by this
  capability.
