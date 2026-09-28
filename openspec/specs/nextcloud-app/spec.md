---
slug: nextcloud-app
title: Nextcloud App Shell
status: in-progress
feature_tier: must
openspec_changes:
  - fix-dashboards-settings-notifications
  - findall-config-filters-sweep
depends_on_adrs: [adr-001, adr-003, adr-008, adr-011, adr-012]   # TODO until ADRs land
created: 2026-05-11
retrofit_extensions:
  - REQ-005
  - REQ-006
  - REQ-007
  - REQ-008
  - REQ-009
---

# Nextcloud App Shell

## Purpose

Define the non-negotiable Nextcloud-native shell guardrails every other Scholiq spec relies on: the OpenRegister/OpenConnector dependency declaration and bootstrap refusal, hash-mode Vue Router, NcEmptyContent empty states, the NL Design double-fallback CSS pattern, the read/write Settings API, and the correct split between the admin settings panel (default register, AI features, credential-signing key — admin-guarded) and the per-user settings dialog (notification preferences), with a single consistent monochrome navigation icon family.

## Why
Insight #19: "Nextcloud as education platform — strong privacy-first positioning (self-hosted = schools control data)." Insight #94: "OSS LMS leaders share dated UX" — being a true Nextcloud-native app is the structural differentiator. This spec defines the non-negotiable shell guardrails (settings dialog, OpenRegister dependency check, Vue Router, NL Design theming, NcEmptyContent fallback) that every other Scholiq spec relies on but none of them owns.

## What
Standard Nextcloud app shell: `appinfo/info.xml` declaring dependency on OpenRegister and OpenConnector; `NcAppSettingsDialog` for user-level settings (notification preferences, default view); `NcAdminSettings` for tenant-level settings (IdP selection, ROD/OSO connection config, AI-feature flags); a hard runtime dependency check on OpenRegister with an `NcEmptyContent` fallback if missing; Vue Router (hash mode) at `src/router/index.js`; NL Design theming via the double-fallback CSS pattern (`--cn-*` vars); `@conduction/nextcloud-vue` (`^0.1.0-beta.1`) as a peer dependency with the conditional webpack alias and dedup aliases as documented in the project rules.

## User Stories
- As a Nextcloud admin, I want Scholiq to refuse to install unless OpenRegister is present so I never end up with a broken UI.
- As a Nextcloud admin, I want a Scholiq admin-settings page where I configure the tenant IdP, the OpenConnector adapters for ROD/OSO/UWLR, and the AI feature flag toggle.
- As a user, I want a Scholiq personal-settings panel where I set my notification preferences (instant vs digest) and default landing dashboard.
- As a user, I want every navigation in Scholiq to be a real URL I can bookmark so deep links to a course, exam, or OPP work via Vue Router hash routes.
- As a user, when no data exists in a list, I want a friendly NcEmptyContent screen with a clear next action so I know what to do.

## Acceptance Criteria
- GIVEN OpenRegister is not installed or is disabled, WHEN Scholiq loads, THEN an `NcEmptyContent` screen explains the dependency and the install link, and no other UI renders.
- GIVEN a Nextcloud admin opens Settings → Administration → Scholiq, WHEN the page loads, THEN they can configure IdP, OpenConnector adapters, and AI feature flags from one panel.
- GIVEN a user navigates to `/index.php/apps/scholiq/#/courses/123`, WHEN the page loads, THEN Vue Router matches the route and the course detail view renders without manual reload.
- GIVEN a user opens a list with zero rows, WHEN the empty state renders, THEN it uses `NcEmptyContent` with title, illustration, and a primary action button.

## Requirements

### Requirement: Declare openregister + openconnector deps and refuse to bootstrap without them
The system MUST declare `openregister` and `openconnector` as `<dependencies>` in `appinfo/info.xml` and refuse to bootstrap without them.

#### Scenario: Missing OpenRegister blocks bootstrap
<!-- @e2e exclude Requires disabling OpenRegister instance-wide, which would break the shared e2e Nextcloud environment for every other app; the dependency declaration is a static appinfo/info.xml assertion. -->
- **GIVEN** the Scholiq app with `openregister` declared as a dependency
- **WHEN** OpenRegister is not installed or enabled
- **THEN** the app refuses to bootstrap and renders an `NcEmptyContent` fallback rather than a broken UI

### Requirement: Every custom page renders a registered component
Every manifest page of `type: "custom"` SHALL name, in `component` (or `slots.main`), a component that
`src/registry.js` registers as a `kind: "page"` entry. `CnPageRenderer` resolves a custom page only against
the app registry, and a missing name renders "This page is empty" with no more than a console warning, so a
library building block (a grid, a dialog, a wizard) is never named directly: a thin learniq view loads the
data, mounts the building block and writes back. `tests/unit-js/registryComponentCoverage.test.mjs` SHALL fail when
any custom page names an unregistered component.

#### Scenario: A custom page naming an unregistered component fails the test suite
- **GIVEN** a manifest page with `type: "custom"` and `component: "CnDataMatrix"`
- **AND** `src/registry.js` has no `CnDataMatrix: page(...)` entry
- **WHEN** `npm run test:js-unit` runs
- **THEN** it fails and names the page and the component

<!-- @e2e exclude A static manifest-against-registry check; tests/unit-js/registryComponentCoverage.test.mjs
     (every type:"custom" page names a component registered as kind:"page") is the assertion. -->

### Requirement: Vue Router in hash mode for all navigation
The system MUST use Vue Router in hash mode for all navigation; custom hash routing or `$emit('navigate')` patterns are forbidden.

#### Scenario: Navigation uses hash-mode router
<!-- @e2e exclude Router-mode is a code-structure guardrail (hash-mode config + absence of $emit('navigate')); asserted by static review/lint, not a DOM behaviour distinguishable from history-mode at runtime. -->
- **WHEN** the user navigates between Scholiq views
- **THEN** the route changes via the hash-mode Vue Router and no custom `$emit('navigate')` hash routing is used

### Requirement: Render NcEmptyContent for every empty state
The system MUST render `NcEmptyContent` for every empty list/state; raw "no data" strings are forbidden.

#### Scenario: Empty list renders NcEmptyContent
<!-- @e2e exclude Cross-cutting UI guardrail across every list view; enforced by component-level convention/lint rather than a single drivable DOM scenario. -->
- **GIVEN** a Scholiq list view whose data set is empty
- **WHEN** the view renders
- **THEN** it shows an `NcEmptyContent` component rather than a raw "no data" string

### Requirement: Use NL Design System double-fallback CSS pattern
The system MUST use the NL Design System double-fallback CSS pattern (`var(--cn-X, var(--color-X, fallback))`); hardcoded colours are forbidden.

#### Scenario: Colours use the double-fallback pattern
<!-- @e2e exclude CSS-authoring guardrail (double-fallback var pattern, no hardcoded colours); enforced by stylelint/static review, not a runtime DOM behaviour. -->
- **WHEN** a Scholiq component sets a colour
- **THEN** it uses `var(--cn-X, var(--color-X, fallback))` and never a hardcoded colour literal

### Requirement: Expose app settings through a read/write Settings API
The system MUST expose the app's persisted settings (the keys managed by `SettingsService`, currently `register`) plus the derived metadata fields `openregisters` (whether OpenRegister is installed) and `isAdmin` (whether the current user is in the admin group) through a JSON Settings API. A GET request MUST return the merged settings + metadata; a POST request MUST persist only the known config keys present in the payload and return the updated merged settings. The frontend settings store and the personal/admin Settings views MUST read and write exclusively through this API.

#### Scenario: Reading current settings
- **WHEN** the frontend requests `GET /apps/scholiq/api/settings`
- **THEN** the response contains every managed config key, an `openregisters` boolean, and an `isAdmin` boolean

#### Scenario: Persisting a changed setting
- **WHEN** the frontend POSTs `{ register: "scholiq" }` to the Settings API
- **THEN** only the `register` config key is written and the response echoes the updated merged settings

#### Notes
- Unknown keys in a POST payload are silently ignored (only `CONFIG_KEYS` are written).
- `isAdmin` is `false` when there is no logged-in user.

### Requirement: Configure default register and AI features via OpenRegister-backed pickers
The default-register picker and the AI-features review table MUST live in the Nextcloud **Admin** settings panel, registered through `appinfo/info.xml` `<settings>` (an admin `IDelegatedSettings` class plus an admin `IIconSection`) and guarded so only administrators can reach the mutating endpoints. They MUST NOT be rendered in the per-user app "User settings" dialog. The register options MUST be loaded from OpenRegister's `/apps/openregister/api/registers` endpoint; the AI feature list MUST be read from the Scholiq Settings API response (`aiFeatures`). Selecting a default register MUST persist it via the Settings API. Loading failures MUST be caught and logged without breaking the panel.

#### Scenario: Admin panel hosts the pickers
<!-- @e2e tests/e2e/spec-coverage/nextcloud-app.spec.ts -->
- **WHEN** an administrator opens Nextcloud Settings → Administration → Scholiq
- **THEN** the default-register picker and AI-features table are shown and the register/AI lists load from OpenRegister and the Settings API

#### Scenario: Non-admin cannot reach the pickers
<!-- @e2e exclude Negative admin-gating: the mutating endpoints carry #[AuthorizedAdminSetting(AdminSettings::class)] (asserted by reasoning + hydra route-auth/semantic-auth gates); the per-user dialog renders only ScholiqNotificationSettings (verified by App.vue #user-settings slot). No non-admin test user is provisioned in the e2e env. -->
- **GIVEN** a signed-in non-admin user
- **WHEN** they open the Scholiq app's per-user "User settings" dialog
- **THEN** no register picker, AI-features table, or credential-signing control is present

### Requirement: Allow the credential signing key to be rotated from settings
The credential-signing key rotation action MUST live in the Nextcloud **Admin** settings panel and MUST be invokable only by an administrator. It MUST rotate the tenant's RS256 credential signing key and surface a localized success/failure message.

#### Scenario: Admin rotates the signing key
<!-- @e2e tests/e2e/spec-coverage/nextcloud-app.spec.ts -->
- **WHEN** an administrator triggers the rotate-signing-key action in the admin panel
- **THEN** the key-rotation endpoint is called and a localized success or failure message is shown

### Requirement: Per-user notification preferences in the user settings dialog
The per-user app "User settings" dialog MUST present the user's Scholiq notification preferences as toggles and MUST read and write them through OpenRegister's override-only notification-preferences endpoint (`GET`/`PUT /apps/openregister/api/notification-preferences`), so a toggle genuinely gates delivery via OpenRegister's dispatcher. The dialog MUST NOT introduce a parallel scholiq-local preference store. Each toggle MUST correspond to a declared `(schema, notification)` rule and MUST be labelled with an English source string (Dutch via l10n).

#### Scenario: User disables a notification type
<!-- @e2e tests/e2e/spec-coverage/nextcloud-app.spec.ts -->
- **GIVEN** the per-user settings dialog listing Scholiq notification types
- **WHEN** the user turns off "Credential issued" and saves
- **THEN** a `PUT /apps/openregister/api/notification-preferences` records the override for that `(schema, notification)` pair
- **AND** the user no longer receives that notification

#### Scenario: Preferences reflect current overrides
<!-- @e2e tests/e2e/spec-coverage/nextcloud-app.spec.ts -->
- **WHEN** the per-user settings dialog opens
- **THEN** it loads the current overrides via `GET /apps/openregister/api/notification-preferences` and renders each toggle in its stored state (default on)

### Requirement: Consistent monochrome navigation icons
Every `menu[]` entry in `src/manifest.json` MUST use an icon from the monochrome Nextcloud `icon-*` family so the navigation renders in a single consistent colour; coloured `icon-category-*` glyphs MUST NOT be mixed into the menu.

#### Scenario: All menu icons are monochrome
<!-- @e2e exclude Static manifest assertion (no `icon-category-*` in any menu[].icon); enforced at build time by tests/validate-manifest.js + the manifest unit test, not a runtime DOM behaviour. -->
- **WHEN** the manifest `menu` array is inspected
- **THEN** every entry's `icon` value is a monochrome `icon-*` class and none is an `icon-category-*` value

### Requirement: Provide a configurable generic OpenRegister object store initialised at boot
The frontend MUST initialise a generic Pinia object store at application boot, configuring it with the OpenRegister object and schema base URLs. The store MUST allow registering named object types (type → schema + register) and fetching objects of a registered type with arbitrary query params, returning an empty array (and warning) for unregistered types and on fetch failure. Boot initialisation MUST also trigger the initial settings fetch.

#### Scenario: Booting the stores
@e2e exclude Pure JS/Pinia store initialization — observable only by instrumenting Vue internals, not by DOM assertions. Covered by unit tests.
- **WHEN** `initializeStores()` runs
- **THEN** the object store is configured with the OR object/schema base URLs and the settings store performs its initial fetch

#### Scenario: Fetching an unregistered type
@e2e exclude Pure JS/Pinia store behavior — no DOM change occurs for an unregistered type warning. Covered by unit tests.
- **WHEN** `fetchObjects` is called for a type that was never registered
- **THEN** it warns and returns an empty array without issuing a request

#### Notes
- Fetch failures are caught, logged, and surface as an empty array — callers never see a rejected promise.

### Requirement: Serve a read-only admin health endpoint and the bundled app manifest
The system MUST expose an admin-only health endpoint reporting OpenRegister connectivity, the count of registered schemas, a 24-hour audit-trail event count, whether LaunchPad is installed, and the last audit-pack export timestamp. The system MUST also serve the bundled `src/manifest.json` blob unchanged via a manifest endpoint (ADR-024 §4).

#### Scenario: Reading health diagnostics
@e2e exclude Admin-only backend API endpoint — returns JSON with no corresponding UI page that renders the health fields. Covered by PHPUnit/Newman API tests.
- **WHEN** an admin requests the health endpoint
- **THEN** the response contains `openregister_connected`, `schemas_registered`, `audit_trail_events_24h`, `launchpad_installed`, and `last_audit_pack_export`

#### Scenario: Serving the manifest
@e2e exclude Backend JSON-passthrough endpoint — returns the raw manifest blob with no UI rendering. The manifest content is exercised indirectly by all SPA navigation tests.
- **WHEN** the frontend requests the manifest endpoint
- **THEN** the bundled `src/manifest.json` is returned as JSON

#### Notes
- Observed: `audit_trail_events_24h` returns `0` and `last_audit_pack_export` returns `null` in v0.1 — placeholders pending an OpenRegister audit-event query API. `openregister_connected` is derived from the presence of the bundled register manifest file, not a live connection probe.

### Requirement: A schema's declared audience is enforced by its authorization block
Every schema that declares who may read its rows in `x-property-rbac` MUST carry an `authorization` block that OpenRegister enforces and that grants read to that audience, because OpenRegister does not read `x-property-rbac` (openregister#4064). A rule of the form "the person in field F reads this row" MUST be enforced as an `authenticated` entry matching F against the caller. Role words MUST map onto the declared groups: teacher to `instructors`, `team-leads`, `coordinators` and `administration-managers`; mentor and study adviser to `team-leads`; coordinator to `coordinators`; principal and finance to `administration-managers`; exam board to `compliance-officers`; manager to `team-leads` and `administration-managers`; admin to OpenRegister's admin bypass. Schemas that relied on the register cascade MUST keep the create and update grants the cascade gave them.

#### Scenario: A learner reads their own grade and not a classmate's
@e2e exclude Enforced by OpenRegister from the shipped register JSON; pinned by tests/Unit/Register/DeclaredAudienceEnforcedTest.php.
- **GIVEN** learner A and learner B, neither in a staff group, each with a GradeEntry
- **WHEN** learner B lists grade entries
- **THEN** only B's own grade entry is returned

#### Scenario: A schema cannot ship an unenforced audience
@e2e exclude Register-content invariant with no UI; pinned by tests/Unit/Register/DeclaredAudienceEnforcedTest.php.
- **WHEN** a schema carries `x-property-rbac` without an `authorization` block
- **THEN** the unit suite fails and names the schema

### Requirement: A schema with lifecycle transitions is not append-only
A schema that declares `x-openregister-lifecycle` transitions MUST NOT be `appendOnly`. Open Register runs a transition as an update of the object and refuses every update on an append-only schema, so the two together make every transition fail. The audit ADR-008 asks for is Open Register's audit trail, which keeps each version of the object. Schemas without a lifecycle (for example `DossierNote`, `WellbeingCheckIn`) keep `appendOnly` and are corrected by a new record.

#### Scenario: A credential can be revoked
@e2e exclude Register-content invariant; pinned by tests/Unit/Register/LifecycleSchemasAreNotAppendOnlyTest.php, which runs one transition for each of the sixteen schemas that were append-only.
- **GIVEN** an issued `Credential`
- **WHEN** a compliance officer fires `revoke`
- **THEN** the credential lands in `revoked` instead of being refused as an update on an append-only schema

### Requirement: A learner runs the transitions on their own rows
A learner MUST be able to run the transitions a learner screen fires on a row that names them, and nobody else's. OpenRegister checks `update` for a transition and `create` without the object, so each schema grants `update` to `{"group": "authenticated", "match": {<person field>: "$userId", "lifecycle": <the states the learner acts in>}}` and, where the learner creates the row, `create` to `authenticated`. Because an open create lets anyone make a row in another learner's name (and then pass RBAC as its owner), the transition's `requires` guard MUST refuse any caller who is not the person on the row, administrators and system calls excepted. The person field is `reviewerId` on PeerReview (`submit` from `assigned`) and `learnerId` on SelfAssessment (`submit` from `draft`), Portfolio (`submit` from `draft` or `active`), LearningRecordExport (`generate` from `requested`; its guard, `LearningRecordExportService`, cannot run yet and is tracked in learniq#983), LearningRecordShare (`grant` and `revoke`) and ProctoringSession (`activate` and `end`). A share MUST be of an export of the same learner. A PortfolioEntry has no transition, so a pre-write veto MUST refuse a non-staff caller writing an entry that is not in their own name or not in their own portfolio. The staff transitions the learner's update grant would otherwise reach (Portfolio `activate` and `archive`, ProctoringSession `fail`) MUST carry a transition `authorization` list of the staff groups. A `requires` guard MUST implement OpenRegister's `LifecycleGuardInterface`, because OpenRegister refuses to run any other.

#### Scenario: A reviewer submits the peer review they were allocated
@e2e exclude Enforced by OpenRegister from the shipped register JSON and the guard; pinned by tests/Unit/Register/LearnerTransitionAccessTest.php (testPeerReviewSubmitIsTheReviewers).
- **GIVEN** a PeerReview allocated by a team lead to learner A, in `assigned`
- **WHEN** learner A fires `submit`
- **THEN** the review moves to `submitted`
- **AND** learner B firing `submit` on it is refused

#### Scenario: A row made in someone else's name is refused at its transition
@e2e exclude Guard behaviour with no UI of its own; pinned by tests/Unit/Register/LearnerTransitionAccessTest.php (testARowMadeInSomeoneElsesNameIsRefusedAtItsTransition).
- **GIVEN** learner B created a LearningRecordShare naming learner A
- **WHEN** learner B fires `grant`
- **THEN** the guard refuses it

### Requirement: LearniqSettings records the instance's segment
The system MUST persist `LearniqSettings` (`segment`: `po`/`vo`/`mbo`/`he`/`corporate`, default `corporate`) as a flat, un-lifecycled singleton OpenRegister object, mirroring the `SovereigntyPolicy` singleton convention.

#### Scenario: An administrator records the school's segment
- **GIVEN** the `LearniqSettings` schema is registered
- **WHEN** an administrator sets `segment` to `po`
- **THEN** the value persists on the `LearniqSettings` object

#### Scenario: An instance with no LearniqSettings object yet defaults safely
- **GIVEN** no `LearniqSettings` object has been created
- **WHEN** the schema's default is read
- **THEN** `segment` defaults to `corporate`, the no-behaviour-change default for existing customers

### Requirement: LearniqSettings is reachable as an admin-only declarative page
`LearniqSettings` MUST render as a manifest-declared index+detail page pair, visible only to `admin`, with no custom Vue component and no bespoke PHP controller.

#### Scenario: An administrator opens the segment setting
- **GIVEN** the `LearniqSettings` page is configured
- **WHEN** an administrator navigates to it
- **THEN** the page renders via the standard declarative data/related widgets, matching every other schema's detail page in this app

### Requirement: Segment-based menu visibility is not implemented by a config-kind change
No `visibleIf` condition MUST reference `segment` (or any runtime path derived from it) in a `config`-kind change, because `visibleIf` resolves exclusively against `manifest.runtime.*`, which only an app-specific PHP `IInitialState` provider plus JS wiring can populate — a `code`-kind change. Declaring such a condition without that wiring would activate the shared library's undefined-runtime fail-safe (hide the item for every tenant), which is a regression, not a feature.

#### Scenario: No menu item's visibleIf references segment yet
- **GIVEN** this change's manifest diff
- **WHEN** every `visibleIf` block in the changed files is inspected
- **THEN** none references `segment` or a `workspace.segment`/`config.segment` runtime path

#### Scenario: The follow-up code change closes the loop
- **GIVEN** a future `code`-kind change adds the PHP `IInitialState` provider and the `src/main.js` runtime population for `segment`
- **WHEN** that change also adds `visibleIf: {"workspace.segment": {...}}` to corporate-flavoured menu items
- **THEN** the gating becomes real, because the runtime path it reads is now actually populated

### Requirement: Menus follow the kind of organisation
Menu entries whose subject belongs to specific kinds of organisation MUST carry `visibleIf: {"workspace.segment": {"in": [...]}}` listing the segments that see them: staff compliance and external training for companies and training institutes; engagement and course evaluation for MBO, higher education, companies and training institutes; work placements (BPV) for MBO; study progress (BSA) for higher education; exam board, exam accommodations, applications, admissions rounds and review board for secondary school and up; subject choices for secondary school, MBO and higher education; school advies for primary and secondary school. Entries of the school shape (people, classes, attendance, schools and locations, pupil dossier, group plans, support requests, report periods and cards, parent conferences) MUST NOT carry a segment gate. This supersedes the `segment-feature-flags` requirement "Segment-based menu visibility is not implemented by a config-kind change": its precondition, an unpopulated `runtime.workspace`, no longer holds since `segment-runtime-bridge`.

#### Scenario: A primary school sees the school shape
- **GIVEN** `runtime.workspace.segment` is `po` and the user is an admin
- **WHEN** the navigation renders
- **THEN** people, attendance, schools, locations, pupil dossier, group plans, support requests, report cards, parent conferences and school advies show
- **AND** compliance, external training, BPV, BSA, exam board, exam accommodations, subject choices and intake do not

#### Scenario: BPV is for MBO
- **GIVEN** each of the six segments
- **WHEN** the BPV group's gate is evaluated
- **THEN** it shows for `mbo` and `corporate` only

### Requirement: The company default keeps every menu
Every `workspace.segment` gate MUST keep `corporate` visible, because `corporate` is the default of every install that never chose a segment (`segment-feature-flags` Decision 2). Every segment literal MUST be one of the six `LearniqSettings.segment` codes. `npm run check:menu-role-gates` MUST fail when either rule breaks.

#### Scenario: An install that never chose a segment
- **GIVEN** `runtime.workspace.segment` is `corporate`
- **WHEN** every menu entry is evaluated for an admin
- **THEN** every entry shows, as before this change

#### Scenario: A gate that forgets corporate
- **GIVEN** a menu entry with `visibleIf: {"workspace.segment": {"in": ["po"]}}`
- **WHEN** `npm run check:menu-role-gates` runs
- **THEN** it fails and names the entry

### Requirement: The wizard says what the segment does
The setup wizard's segment step MUST tell the admin that the app shows the menus that fit the chosen kind, with a Dutch catalogue entry.

#### Scenario: Reading the segment step
- **GIVEN** the setup wizard's `segment` step
- **WHEN** its body is read in English or Dutch
- **THEN** it says the app shows the menus that fit the choice, and that the choice can change later under App settings

### Requirement: Every object read MUST name its register and schema inside `filters`

Every call to OpenRegister's `ObjectService::findAll()` under `lib/` MUST pass the register and the schema as `$config['filters']['register']` and `$config['filters']['schema']`. A config MUST NOT carry `register` or `schema` at its top level, whether the config is written inline or assembled in a variable. A config MUST NOT carry the same key twice. OpenRegister's `prepareFindAllConfig()` reads the scope from `filters` only, so a top-level key leaves the read without a schema or with a stale one.

#### Scenario: A guard reads a learner's rows from the right schema

- **GIVEN** a lifecycle guard that looks up the enrolments of learner `leerling-001`
- **WHEN** it calls `ObjectService::findAll()`
- **THEN** the config carries `filters.register = "learniq"` and `filters.schema = "enrolment"` next to `filters.learnerId = "leerling-001"`
- **AND** the config has no top-level `register` or `schema` key

#### Scenario: A config built in a variable is scoped the same way

- **GIVEN** a service that assembles its findAll config in `$config` before the call
- **WHEN** the config is passed to `ObjectService::findAll($config)`
- **THEN** `register` and `schema` sit under `$config['filters']`

#### Scenario: The regression test fails on the old shape

- **GIVEN** a findAll call under `lib/` with `'schema' => 'lesson'` at the top level of its config
- **WHEN** `FindAllConfigScopeTest` runs
- **THEN** it fails and names the file, the line and the key

#### Scenario: The regression test fails on a duplicate filters key

- **GIVEN** a findAll config with two `'filters'` keys
- **WHEN** `FindAllConfigScopeTest` runs
- **THEN** it fails with `duplicate filters` and the line of the second key

### Requirement: Every object read MUST filter only on properties its target schema declares

Every `ObjectService::findAll()` call under `lib/` MUST use, as a `filters` key, either a query context key (`register`, `schema`, `registers`, `schemas`, `extend`, `@self`, or a key starting with `_`) or a property that the target schema in `lib/Settings/learniq_register.json` declares. The key MUST be compared verbatim: on the `findAll()` path OpenRegister does not split a key on underscores, so `tenant_id` is valid wherever the schema declares `tenant_id`. An object id MUST travel in the config's `ids`, not as an `id` or `uuid` filter.

#### Scenario: A course with a published lesson can be published

- **GIVEN** a Course `course-7` in tenant `tenant-a` and a published Lesson with `courseId = course-7` and `tenant_id = tenant-a`
- **WHEN** `CoursePublishGuard` checks the Course's `publish` transition against a store that answers like OpenRegister
- **THEN** the guard allows the transition
- **AND** the lookup's filters carry `tenant_id = tenant-a` whole, with no `tenant` key

#### Scenario: A draft lesson, another course's lesson or another tenant's lesson does not count

- **GIVEN** only a draft Lesson on `course-7`, a published Lesson on `course-8` and a published Lesson on `course-7` in `tenant-b`
- **WHEN** `CoursePublishGuard` checks `course-7` in `tenant-a`
- **THEN** the guard refuses the transition

#### Scenario: A new undeclared filter key fails at unit time

- **GIVEN** a `findAll()` call under `lib/` whose filters name a property the target schema does not declare, and which is not in the test's known list
- **WHEN** `FindAllFilterKeysAreDeclaredTest` runs
- **THEN** it fails and names the file, the line, the schema and the key

#### Scenario: A fixed read must leave the known list

- **GIVEN** an entry in the test's known list that the scan no longer finds
- **WHEN** `FindAllFilterKeysAreDeclaredTest` runs
- **THEN** it fails and asks for the entry to be deleted

## Standards
Nextcloud OCP (`IAppManager`, `IConfig`, `IUserSession`, `IRootFolder`, `IGroupManager`, `Calendar\IManager`, `Notification\IManager`, `Talk\IBroker`, `Activity\IManager`), NL Design System tokens, WCAG 2.1 AA.

## Data Model
See `docs/ARCHITECTURE.md`. Uses: `TenantSetting`, `UserSetting`. Other entities live in their respective specs.

## Out of Scope
- Authoring of business-domain features (every other spec owns its own domain).
- Custom Nextcloud theme — we consume the NL Design tokens, we do not ship a global theme.
- ExApp / sidecar architecture (PHP-only at MVP).
