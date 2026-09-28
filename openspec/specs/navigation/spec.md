# navigation Specification

## Purpose
Bring scholiq into ADR-044 compliance by introducing the ADR-037 modular fragment
pipeline (`src/manifest.d/` + `require.context` collection) and wiring manifest
assembly through the shared `buildManifest(base, fragments, menuLayout)` helper from
`@conduction/nextcloud-vue`, with navigation layout data in `src/menu-layout.json`.
Lifts configuration/admin leaves into the settings foldout via
`menu-layout.json#settingsSection`. The hard invariant: every pre-existing menu entry
remains reachable and every page stays routable.

## Requirements

### Requirement: REQ-AMP-001 — scholiq MUST introduce the ADR-037 modular fragment pipeline

Per ADR-037, scholiq MUST add a `src/manifest.d/` directory as the fragment
collection root and MUST update `src/main.js` to gather fragment files via
`require.context('./manifest.d', true, /\.json$/)` (or equivalent webpack import
pattern), producing a `fragments` array passed to `buildManifest`. The base manifest
(`src/manifest.json`) MUST remain the canonical source of observability, deepLinks, and
pages; fragment files extend the menu only. No fragment file may duplicate a key
present in the base manifest.

#### Scenario: Fragment pipeline collects manifest.d files

- GIVEN `src/manifest.d/` exists and contains one or more `.json` fragment files
- WHEN the scholiq bundle is built
- THEN `require.context` (or equivalent) collects every fragment in `manifest.d/`
- AND the collected fragments are forwarded to `buildManifest` as the `fragments` argument
- AND the resulting effective manifest contains the merged menu from base + fragments

#### Scenario: Empty fragment directory is safe

- GIVEN `src/manifest.d/` exists but contains no fragment files
- WHEN the scholiq bundle is built
- THEN `buildManifest` is called with an empty `fragments` array
- AND the effective manifest is identical to the base manifest menu

### Requirement: REQ-AMP-002 — scholiq MUST build its effective manifest via the shared buildManifest helper

Per ADR-044, scholiq MUST NOT inline its own manifest assembly logic in `src/main.js`.
Instead it MUST call `buildManifest(base, fragments, menuLayout)` imported from
`@conduction/nextcloud-vue`, where `base` is the parsed `src/manifest.json`, `fragments`
is the array collected by the ADR-037 pipeline (REQ-AMP-001), and `menuLayout` is the
parsed `src/menu-layout.json`. The return value of `buildManifest` MUST be the manifest
object passed to the Vue root as the `manifest` prop and used by `routesFromManifest`.

#### Scenario: buildManifest is called at bootstrap

- GIVEN `src/main.js` imports `buildManifest` from `@conduction/nextcloud-vue`
- WHEN the app boots
- THEN `buildManifest(base, fragments, menuLayout)` is called exactly once
- AND the result is assigned to the manifest variable used by the Vue root and router
- AND no second manifest assembly path exists in the file

#### Scenario: menu-layout.json controls relocations

- GIVEN `src/menu-layout.json` contains a `relocations` entry moving a menu item
- WHEN the app boots and calls `buildManifest`
- THEN the effective manifest reflects the relocation declared in `menu-layout.json`
- AND the base `src/manifest.json` `menu[]` array is not modified to express the relocation

### Requirement: REQ-AMP-003 — scholiq's configuration/admin leaves MUST be lifted into the settings foldout via menu-layout.json#settingsSection

Per ADR-044, leaves that belong to the settings foldout MUST be declared in
`menu-layout.json#settingsSection` rather than through ad-hoc `section: "settings"`
flags scattered across individual menu entries. scholiq MUST declare the following
leaf ids in `settingsSection`: `DataExchange`, `XapiStatementsMenu`, `AssistantMenu`,
and `FeaturesRoadmapMenu`. After this change, `section: "settings"` flags on
individual `menu[]` entries for these leaves MAY be removed in favour of the
`settingsSection` list; the foldout content MUST be equivalent.

#### Scenario: Settings foldout contains the declared leaves

- GIVEN `menu-layout.json#settingsSection` lists `DataExchange`, `XapiStatementsMenu`, `AssistantMenu`, `FeaturesRoadmapMenu`
- WHEN the app renders the settings foldout
- THEN all four leaves appear in the foldout
- AND no other primary-nav entry appears in the foldout unless also declared in `settingsSection`

#### Scenario: Admin-gated leaf respects existing visibleIf in foldout

- GIVEN `AdminHealthMenu` carries `visibleIf: { "user.primaryRole": { "eq": "admin" } }`
- WHEN a non-admin user opens the settings foldout
- THEN `AdminHealthMenu` is not visible in the foldout
- AND all non-gated foldout leaves remain visible

### Requirement: REQ-AMP-004 — INVARIANT: every pre-existing menu entry MUST remain reachable and every page MUST stay routable after the pipeline refactor

The refactor MUST NOT drop, hide, or reroute any pre-existing menu entry or page route
(ADR-044 hard invariant). Every leaf that was reachable before the refactor (whether in
primary nav, footer, or settings foldout) MUST remain reachable after it. Every
`pages[]` entry and its route MUST survive unchanged in the effective manifest produced
by `buildManifest`, so that existing deep links, bookmarks, and e2e test routes continue
to resolve.

#### Scenario: All pre-existing routes resolve after refactor

- GIVEN the effective manifest produced by `buildManifest` after the refactor
- WHEN the vue-router is initialised from `routesFromManifest(effectiveManifest)`
- THEN every route that existed before the refactor is present in the router
- AND navigating to `/courses`, `/enrolments`, `/attendance/records`, `/grades/entries`, `/learning-plans`, `/assessments`, `/credentials`, `/learner-profiles`, `/data-exchange/jobs`, `/xapi-statements`, `/structure/rollover`, and `/` all resolve to their respective page components

#### Scenario: Deep link to a page survives the pipeline refactor

- GIVEN the refactor is deployed
- WHEN a user opens `/apps/scholiq/#/courses/some-uuid` directly by URL
- THEN the Course detail page renders for `some-uuid`
- AND no 404 or redirect-to-dashboard occurs

### Requirement: REQ-LPC-003 — INVARIANT: every former child leaf's page route MUST remain routable and every leaf MUST be reachable as a card or direct deep link

The cards-collapse MUST NOT remove any `pages[]` entry, rename any route, or prevent
direct deep-link navigation to any former child leaf. All ten former child leaf pages
(`Courses`, `Curriculum`/`Programmes`, `LearningPlans`, `Assignments`, `Assessments`,
`Grades`/`GradeEntries`, `LearnerProfiles`, `Enrolments`, `Attendance`/`AttendanceRecords`,
`Credentials`) MUST remain declared in `pages[]` with their existing routes unchanged.
Each MUST be reachable both by clicking its card on the landing page and by navigating
directly to its route (deep link).

#### Scenario: All former Learning leaf routes remain routable after collapse

- GIVEN the cards-collapse refactor is deployed
- WHEN a user navigates directly to `/courses`, `/curriculum/programmes`, `/learning-plans`, `/assignments`, `/assessments`, or `/grades/entries`
- THEN the corresponding page renders without error
- AND no redirect to a landing page or dashboard occurs for a direct deep-link navigation

#### Scenario: All former People leaf routes remain routable after collapse

- GIVEN the cards-collapse refactor is deployed
- WHEN a user navigates directly to `/learner-profiles`, `/enrolments`, `/attendance/records`, or `/credentials`
- THEN the corresponding page renders without error
- AND no redirect to a landing page or dashboard occurs for a direct deep-link navigation

#### Scenario: Card on a landing page navigates to the leaf's existing route

- GIVEN the user is on the LearningCards or PeopleCards landing page
- WHEN the user activates any card
- THEN the browser navigates to the same route the corresponding leaf used before the collapse
- AND the URL is unchanged compared to pre-collapse navigation to that leaf

### Requirement: App health is not an in-app navigation surface
The system MUST NOT expose an `App health` page or menu entry inside the Scholiq app. The `AdminHealthMenu` menu entry and the `AdminHealth` page (route `/admin/health`) MUST be removed from `src/manifest.json`, and the `ScholiqAdminHealth` component and its registry entry MUST be removed. Schema/data-health MUST be governed from OpenRegister's admin Data-health settings form instead; Scholiq MUST NOT duplicate it.

#### Scenario: No App-health menu entry renders
- **GIVEN** an authenticated admin user opens the Scholiq app
- **WHEN** the left navigation renders
- **THEN** no `App health` menu entry is present in the navigation, in the settings foldout, or in the footer

#### Scenario: The App-health route no longer resolves in-app
<!-- @e2e exclude Negative-route/absence assertion — verified by the manifest unit test (no `AdminHealth` page id, no `/admin/health` route) and the registry unit test (no `ScholiqAdminHealth` entry), not a positive DOM behaviour. -->
- **GIVEN** the App-health removal is deployed
- **WHEN** the manifest `pages[]` and `menu[]` and `src/registry.js` are inspected
- **THEN** there is no `AdminHealth` page, no `AdminHealthMenu` menu entry, and no `ScholiqAdminHealth` registry entry or import

### Requirement: The app opens on a top-level Dashboard and the Insight group is dissolved
The system MUST land each user on the role-aware `Dashboard` page (route `/`, component `ScholiqDashboards`) at app root, MUST surface `Dashboard` as a top-level navigation item, and MUST surface `Compliance` as a top-level navigation item. The `GroupInsight` group MUST NOT appear as a navigation group once emptied; the relocation MUST be expressed in `src/menu-layout.json#relocations` (`Dashboard` and `Compliance` → top level).

#### Scenario: Dashboard and Compliance are top-level items
- **GIVEN** an authenticated user opens the Scholiq app
- **WHEN** the left navigation renders
- **THEN** `Dashboard` appears as a top-level navigation item and `Compliance` appears as a top-level navigation item (subject to its existing role gating)
- **AND** no `Insight` group is shown

#### Scenario: App root lands on the dashboard
- **GIVEN** an authenticated user
- **WHEN** they open the Scholiq app root
- **THEN** the role-aware `ScholiqDashboards` page renders and the active navigation item is the top-level `Dashboard`

### Requirement: Learning is a navigable domain dashboard with collapsible sub-children
The `GroupLearning` menu entry MUST be a parent that is both navigable (a `route` landing on the `LearningDashboard` domain dashboard, `/learning`) and collapsible over its six child leaves. The six leaves (`Courses`, `Curriculum`, `LearningPlans`, `Assignments`, `Assessments`, `Grades`) MUST be removed from `src/menu-layout.json#removals` so they render as sub-items, and the `learning-dashboard.json` fragment MUST repoint `GroupLearning.route` at the `LearningDashboard` page. The former `LearningCards` tile-grid landing MUST be retired.

#### Scenario: Learning parent is navigable and expandable
- **GIVEN** an authenticated user opens the Scholiq app
- **WHEN** the left navigation renders
- **THEN** `Learning` shows a disclosure control and, when activated as a link, navigates to `/learning` (the `LearningDashboard`)
- **AND** expanding `Learning` reveals its six sub-items: Courses, Curriculum, Learning plans, Assignments, Assessments, Grades

#### Scenario: Learning sub-items navigate to their existing routes
- **GIVEN** the `Learning` group is expanded
- **WHEN** the user activates the `Courses` sub-item
- **THEN** the browser navigates to the `Courses` route (`/courses`)
- **AND** activating the `Grades` sub-item navigates to the `GradeEntries` route

### Requirement: People is a navigable domain dashboard with collapsible sub-children
The `GroupPeople` menu entry MUST be a parent that is both navigable (a `route` landing on the `PeopleDashboard` domain dashboard, `/people`) and collapsible over its four child leaves. The four leaves (`LearnerProfilesMenu`, `Enrolments`, `Attendance`, `Credentials`) MUST be removed from `src/menu-layout.json#removals` so they render as sub-items, and the `people-dashboard.json` fragment MUST repoint `GroupPeople.route` at the `PeopleDashboard` page. The former `PeopleCards` tile-grid landing MUST be retired.

#### Scenario: People parent is navigable and expandable
- **GIVEN** an authenticated user opens the Scholiq app
- **WHEN** the left navigation renders
- **THEN** `People` shows a disclosure control and, when activated as a link, navigates to `/people` (the `PeopleDashboard`)
- **AND** expanding `People` reveals its four sub-items: Learners, Enrolments, Attendance, Credentials

#### Scenario: People sub-items navigate to their existing routes
- **GIVEN** the `People` group is expanded
- **WHEN** the user activates the `Learners` sub-item
- **THEN** the browser navigates to the `LearnerProfiles` route
- **AND** activating the `Credentials` sub-item navigates to the `Credentials` route (`/credentials`)

### Requirement: Features & roadmap lives in the footer beside Documentation
The `FeaturesRoadmapMenu` entry MUST be removed from `src/menu-layout.json#settingsSection` so it falls back to its base-manifest `section:"footer"` placement beside `Documentation`, matching the fleet convention (pipelinq/opencatalogi/docudesk).

#### Scenario: Features & roadmap renders in the footer
- **GIVEN** an authenticated user opens the Scholiq app
- **WHEN** the left navigation renders
- **THEN** `Features & roadmap` appears in the footer section next to `Documentation`
- **AND** it is not present in the settings foldout

### Requirement: School-year rollover lives in the Settings foldout
The `Rollover` entry (route `RolloverWizard`, admin-gated) MUST be added to `src/menu-layout.json#settingsSection` so it renders inside the Nextcloud settings foldout (gear icon) rather than as a top-level item.

#### Scenario: Rollover renders in the settings foldout for an admin
- **GIVEN** an authenticated admin user opens the Scholiq app
- **WHEN** they open the settings foldout
- **THEN** `School-year rollover` appears inside the foldout
- **AND** it is not present as a top-level navigation item

### Requirement: INVARIANT — all retained routes and deep links remain reachable after the restructure
The restructure MUST NOT remove or rename any retained `pages[]` route, and MUST NOT leave any manifest `deepLinks` entry pointing at a removed route. All ten former Learning/People leaf pages MUST remain declared and directly deep-linkable, and the four `deepLinks` entries (`course`, `enrolment`, `learner-profile`, `credential`) MUST resolve. Only the `AdminHealth` route is removed by this change; no other route may 404 as a result.

#### Scenario: Former leaf routes remain directly deep-linkable
- **GIVEN** the restructure is deployed
- **WHEN** a user navigates directly to `/courses`, `/enrolments`, `/credentials`, or any other retained leaf route
- **THEN** the corresponding page renders without error and without redirect to a landing page or dashboard

#### Scenario: Manifest deep links reference no removed route
<!-- @e2e exclude Static manifest assertion — the four deepLinks urlTemplates are checked by tests/validate-manifest.js / the manifest unit test against pages[]; not a runtime DOM behaviour. -->
- **GIVEN** the restructure is deployed
- **WHEN** the manifest `deepLinks` array is inspected
- **THEN** every `urlTemplate` targets a route that still exists in `pages[]`
- **AND** none targets the removed `AdminHealth` (`/admin/health`) route
