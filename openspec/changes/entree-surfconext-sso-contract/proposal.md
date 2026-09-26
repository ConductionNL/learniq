---
kind: code
depends_on: []
---

## Why

`findings.md#13.5` (SHOULD): "Entree Federatie and SURFconext, eduID" — verified at HEAD, the nearest hit is
`lib/Settings/learniq_register.json#DataExchangeJob.target`'s example value `surfconext`, used only as a
docblock example string; no SSO/login code exists anywhere in the tree. `M3-integrations.md` row I7 confirms
seven of eleven competitor systems document readiness (`ans`: "SSO via SURFconext"; `itslearning`'s own
developer page: "ready integrations with Entree and SURFconext" — the clearest positive statement in the
whole corpus).

`data-exchange`'s own spec already draws the correct boundary and this change does not move it: "Federated
authentication (DigiD / SURFconext / eduID) is OUT of this spec — it MUST be handled by a Nextcloud-auth
-provider + OpenConnector; Scholiq only persists the pseudonymous identifiers on LearnerProfile (already
does)." That line stays true here — this change does not build an IdP client, a SAML/OIDC handshake, or
anything OpenConnector-adjacent. What is genuinely missing, and genuinely learniq's own concern per that same
spec's own framing ("Scholiq only persists the resulting pseudonymous identifiers"), is the DECLARATION of
which external attribute maps to which `LearnerProfile` field once Nextcloud's own `user_saml`/`user_oidc`
apps have already authenticated the user — today there is no such declaration at all, so even a correctly
configured SURFconext login has nowhere to hand `eckId`/role information off to learniq's own schema.

D3's contracts pattern doesn't apply here — this is not a `DataExchangeJob`/`DataMappingProfile`-shaped
problem (no batch job, no scope, no lifecycle to run); it is a login-time attribute hand-off. Per the brief
this change is kept deliberately separate from the job-type work: `uwlr-eduv-basispoort-contract` (#914)
ships the *content-access* SSO hand-off to Basispoort/Entree as a `DataMappingProfile` seed — a different
concern, learniq handing a pupil OFF to a third party. This change is a third party (Entree
Federatie/SURFconext/eduID) handing a user's identity INTO learniq.

This is the first change in a new `identity-federation` capability (the name `data-exchange`'s own header
already reserves in its `replaces_thin_slice_of` list — `data-exchange` absorbed a thin slice of it and
explicitly excluded the rest, which this change now picks up as its own capability rather than reopening
`data-exchange`'s settled scope).

Typed `kind: code`: it adds one new declarative schema (`SsoAttributeMapping`, would be `config` alone) AND
one small, pure PHP service (`SsoAttributeMappingApplier`) with no side effects and no event wiring — kept
deliberately thin so the whole change fits one `code`-kind cycle rather than needing an ADR-032 chain split.

**What this change does NOT do, and why**: `user_saml` and `user_oidc` are not present in this repository, so
their exact provisioning-event class names and attribute-storage API cannot be verified from here — the same
posture `DataExchangeRunHandler`'s own `OPENCONNECTOR_RUN_PATH` docblock takes toward an unverified endpoint
("the assumption is wrong... flagged rather than silently 'fixed'"). Rather than fabricate a listener against
an event class that may not exist, this change ships the DECLARATION (the mapping schema) and the PURE
APPLICATION logic (given an attribute bag, compute the LearnerProfile field updates) as a standalone,
directly callable, fully tested service — and documents the actual event-listener wiring as an explicit,
named follow-up once `user_saml`/`user_oidc` are available to verify against.

## What Changes

- Add `SsoAttributeMapping` (new schema): `provider` (enum `saml | oidc`), `externalAttribute` (the SAML
  attribute name or OIDC claim name), `learniqField` (enum `eckId | schoolId | givenName | familyName |
  role`), `externalValue` and `roleValue` (both nullable — only meaningful when `learniqField: role`:
  `externalValue` is the attribute VALUE to match, e.g. external group `"docent"`, and `roleValue` is the
  `LearnerProfile.roles` enum member it maps to, e.g. `instructor`), `active` (default true), `tenant_id`.
  Lifecycle mirrors `DataMappingProfile`'s own shape:
  `draft` (initial) → `active` → `archived` → `active` (reactivate).
- This schema is the "settings surface" the brief asks for: per ADR-024/031 (declarative business logic, no
  bespoke PHP CRUD controller), `SsoAttributeMapping` gets the same generic OpenRegister index+detail surface
  every other schema in this register gets (the same mechanism that already makes `DataMappingProfile`
  itself admin-editable with zero custom Vue) — no new manifest.json entry or custom view needed.
- New `lib/Service/SsoAttributeMappingApplier.php`: pure function service — `apply(array $attributes, string
  $provider, string $tenantId): array` — loads active `SsoAttributeMapping` rows for the given provider via
  `ObjectService::findAll`, and returns the computed `LearnerProfile` field-update array (e.g. `['eckId' =>
  '...', 'roles' => ['instructor']]`, accumulating every `learniqField: role` match into the `roles` array
  rather than overwriting). No side effects, no direct `LearnerProfile` write — the caller (a follow-up
  listener, once `user_saml`/`user_oidc`'s real provisioning event is confirmed) is responsible for applying
  the returned updates. Explicitly documented as NOT wired to any Nextcloud event in this change.
- No changes to `data-exchange`'s existing "Federated authentication is out of scope" requirement.

## Impact

- Affected specs: new `identity-federation` capability (ADDED: `SsoAttributeMapping` persistence +
  attribute-application requirements). `data-exchange`'s existing scope is unchanged and unreferenced.
- Affected code: `lib/Settings/learniq_register.json` (new schema), new
  `lib/Service/SsoAttributeMappingApplier.php`, new `tests/Unit/Service/SsoAttributeMappingApplierTest.php`,
  new `tests/Unit/Settings/SsoAttributeMappingRegisterTest.php`.
- Explicitly NOT shipped: any `user_saml`/`user_oidc` event listener, any IdP client, any actual login-time
  wiring. Tracked as a named follow-up once those apps' real event surface can be verified from a checkout
  that has them installed.
