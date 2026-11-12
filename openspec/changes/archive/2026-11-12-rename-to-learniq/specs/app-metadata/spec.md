# app-metadata Specification (delta for `rename-to-learniq`)

## ADDED Requirements

### Requirement: The app's identity is a single value repeated at every boundary, not independently chosen per file

The app id, PHP namespace, OpenRegister register slug, MCP tool provider id, and navigation route id
MUST all derive from one identity value (`learniq`). No boundary MAY carry a different casing,
abbreviation, or legacy value once this change ships.

#### Scenario: Every identity boundary agrees after the rename

- **GIVEN** a fresh install of the renamed app
- **WHEN** `appinfo/info.xml`'s `<id>` and `<namespace>`, the PHP `namespace OCA\Learniq` declarations,
  the `composer.json` `psr-4` autoload key, the OpenRegister register slug in
  `lib/Settings/learniq_register.json` (`x-openregister.app` and the nested schema `slug`), the
  `src/manifest.json` `deepLinks[].registerSlug` entries, the MCP tool provider id returned by
  `lib/Mcp/*ToolProvider.php`, and the `learniq.page.index` navigation route id are inspected
- **THEN** every one of them MUST read `learniq` (or `OCA\Learniq` for the namespace form)
- **AND** none of them MUST contain the string `scholiq` (case-insensitive) outside historical
  `openspec/changes/archive/` records and the repair step that migrates old data

@e2e exclude static identity-consistency check across manifest/config files, not a UI flow.

### Requirement: Stored per-install configuration survives the identity rename

Every `IAppConfig` value stored under the `scholiq.*` key namespace on an existing install — including
`scholiq.actions` (the ADR-023 action-authorization matrix), the `scholiq.credential.signing.*` key
family, and `scholiq.course` / `scholiq.module` / `scholiq.listCourses` / `scholiq.getCourseDetails` /
`scholiq.credentialVerify.verify` — MUST be readable under the corresponding `learniq.*` key after the
app upgrades, without the admin re-entering any value.

#### Scenario: Action-authorization matrix is preserved across the rename

- **GIVEN** an install where an admin has customized `scholiq.actions` away from the all-admin default
  (e.g. `course.publish` mapped to `["teachers"]`)
- **WHEN** the app upgrades and its `<post-migration>` repair steps run
- **THEN** `IAppConfig::getValueString('learniq', 'actions')` MUST return the same customized mapping
- **AND** `IAppConfig::getValueString('scholiq', 'actions')` reading afterward MUST NOT be relied upon by
  any code path (the old key is not deleted, but nothing MUST read it after migration)

#### Scenario: A second migration run is a no-op

- **GIVEN** an install where the config-key migration has already completed once
- **WHEN** the `<post-migration>` repair step runs again (e.g. on a subsequent unrelated app upgrade)
- **THEN** it MUST NOT overwrite an already-migrated `learniq.*` value
- **AND** it MUST NOT error or log a failure

@e2e exclude config-key migration is an install/upgrade-time repair step, verified via `occ` and direct
`IAppConfig` inspection, not a browser flow.

### Requirement: Pre-existing OpenRegister objects resolve after the register-slug migration

Objects created under the `scholiq` register slug before this change MUST remain readable, writable, and
correctly attributed (owner, permissions, relations) after the register slug becomes `learniq`. The
migration operates on the register row's `slug` column only; it MUST NOT move, copy, or touch any shard
table, because shard tables are keyed by the register's numeric id, not its slug.

#### Scenario: A pre-existing Course object resolves under the new slug

- **GIVEN** a Course object created via OpenRegister while the register slug was `scholiq`
- **WHEN** the register-slug repair step has run and the register slug is now `learniq`
- **THEN** `ObjectService::find(id: <the same id>, register: 'learniq', schema: 'course')` MUST return the
  same object with the same property values, owner, and relations
- **AND** a lookup with `register: 'scholiq'` MUST fail (the old slug no longer resolves), so no code path
  is silently reading a stale slug

#### Scenario: The register-slug migration is idempotent and non-destructive

- **GIVEN** an install where the register-slug repair step has already run once
- **WHEN** it runs again
- **THEN** it MUST detect the slug is already `learniq` and make no further change
- **AND** at no point during either run MUST any object row be deleted or any shard table be dropped or
  renamed

@e2e exclude data-migration correctness is verified via direct OpenRegister API calls against seeded
pre-migration fixtures, not a UI flow.

### Requirement: Product framing is factual and audience-neutral, not education-exclusive

`appinfo/info.xml` `<summary>` and `<description>` (both `lang="en"` and `lang="nl"`) MUST describe the
app as a learning system usable by a school, a training provider, or a company's L&D department, and
MUST NOT describe it as education-only (e.g. MUST NOT retain "LVS + LMS for education" framing) or claim
a capability the app does not implement.

#### Scenario: The English summary names all three audiences

- **WHEN** `appinfo/info.xml`'s `<summary lang="en">` is read
- **THEN** it MUST NOT contain the phrase "for education" as the sole audience description
- **AND** it MUST reference at least a school/training-provider/company framing consistent with the
  compliance-training features the app already ships (AVG/BIO/NIS2 refreshers, bulk enrolment,
  attestations, credential expiry, coverage reporting)

#### Scenario: The Dutch summary matches the English one in scope, not only in wording

- **WHEN** `appinfo/info.xml`'s `<summary lang="nl">` is read
- **THEN** it MUST describe the same three audiences as the English summary (not a literal translation
  that keeps an education-only framing while the English text was broadened)

@e2e exclude static manifest-copy check, not a UI flow.
