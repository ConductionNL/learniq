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

### Requirement: The app opens on Vandaag at the top of a role-grouped sidebar
The system MUST land each user on the role-aware dashboard (route `/`, component `ScholiqDashboards`) at app root. The sidebar MUST show it as its first item, "Vandaag", in an unlabelled top block with "Mijn leren" (and "Groepen" for the roles that teach groups). The remaining items MUST sit in labelled groups named after what the role works on: "Onderwijs", then "Leerlingen" for the roles that work with pupils. No `Insight` group, and no `Dashboard`, `Learning` or `People` parent, MUST appear in the sidebar. The labels above are the Dutch ones the board `learniq/LqDashboard` draws.

#### Scenario: A teacher sees the top block and two groups
@e2e exclude Menu layout; pinned by the manifest unit test against the drawn sidebar of learniq/LqDashboard, not a browser-observable behaviour of its own.
- **GIVEN** an authenticated teacher opens the app
- **WHEN** the sidebar renders
- **THEN** its unlabelled top block reads "Vandaag", "Mijn leren", "Groepen"
- **AND** below it follow the group "Onderwijs" and the group "Leerlingen"
- **AND** no `Insight` group and no `Dashboard`, `Learning` or `People` parent is shown

#### Scenario: App root lands on Vandaag
@e2e exclude Menu layout; pinned by the manifest unit test against the drawn sidebar of learniq/LqDashboard, not a browser-observable behaviour of its own.
- **GIVEN** an authenticated user
- **WHEN** they open the app root
- **THEN** the role-aware `ScholiqDashboards` page renders and the active sidebar item is "Vandaag"

### Requirement: Learning pages are flat items in the Onderwijs group
The "Onderwijs" group MUST list its items one level deep: no item in it MUST be a navigable parent or carry a disclosure control. For a teacher the group reads "Mijn rooster", "Lessen en opdrachten", "Cijfers"; for a learner it reads "Mijn rooster", "Cursuscatalogus", "Mijn leerdossier", "Inchecken", "Mijn werkgroepen", "Mijn evaluaties", "Keuzelessen", "Vakken kiezen". The former Learning leaves (`Courses`, `Curriculum`, `LearningPlans`, `Assignments`, `Assessments`, `Grades`) that no drawn item opens MUST stay routable and MUST be reachable as a card or link on the page of the item that covers them. The `LearningCards` tile-grid landing stays retired.

#### Scenario: The teacher's Onderwijs group has no sub-items
@e2e exclude Menu layout; pinned by the manifest unit test against the drawn sidebar of learniq/LqDashboard, not a browser-observable behaviour of its own.
- **GIVEN** an authenticated teacher opens the app
- **WHEN** the sidebar renders
- **THEN** the group "Onderwijs" lists "Mijn rooster", "Lessen en opdrachten" and "Cijfers"
- **AND** none of them shows a disclosure control

#### Scenario: A former Learning leaf still opens
@e2e exclude Menu layout; pinned by the manifest unit test against the drawn sidebar of learniq/LqDashboard, not a browser-observable behaviour of its own.
- **GIVEN** the sidebar shows no item for `Courses`
- **WHEN** a user opens `/courses` directly
- **THEN** the courses page renders without error

### Requirement: Pupil pages are flat items in the Leerlingen group
The "Leerlingen" group MUST list its items one level deep, with no navigable parent and no disclosure control, and MUST only render for the roles the boards draw it for. For a teacher, coordinator or study adviser it reads "Leerlingen", "Aanwezigheid", "Voortgang", "Zorg en dossier"; for hr and administrators "Leerlingen", "Aanwezigheid", "Personen", "Compliance"; for a compliance officer "Compliance"; for a team lead "Voortgang", "Aanmeldverzoeken"; for a confidential counsellor or care coordinator "Vertrouwelijke notities", "Vertrouwelijke meldingen". The former People leaves (`LearnerProfilesMenu`, `Enrolments`, `Attendance`, `Credentials`) MUST stay routable; the ones no drawn item opens MUST be reachable as a card or link on the page of the item that covers them. The `PeopleCards` tile-grid landing stays retired.

#### Scenario: The teacher's Leerlingen group has no sub-items
@e2e exclude Menu layout; pinned by the manifest unit test against the drawn sidebar of learniq/LqDashboard, not a browser-observable behaviour of its own.
- **GIVEN** an authenticated teacher opens the app
- **WHEN** the sidebar renders
- **THEN** the group "Leerlingen" lists "Leerlingen", "Aanwezigheid", "Voortgang" and "Zorg en dossier"
- **AND** none of them shows a disclosure control

#### Scenario: A learner has no Leerlingen group
@e2e exclude Menu layout; pinned by the manifest unit test against the drawn sidebar of learniq/LqDashboard, not a browser-observable behaviour of its own.
- **GIVEN** an authenticated learner opens the app
- **WHEN** the sidebar renders
- **THEN** it shows the top block and the group "Onderwijs" only

### Requirement: Features & roadmap lives in the settings foldout, under Over de app
The `FeaturesRoadmapMenu` entry MUST stay in `src/menu-layout.json#settingsSection`. The foldout MUST show it as "Functies en roadmap" under "Over de app", next to "Marktplaats". The footer of the sidebar MUST hold only "Hulp en uitleg".

#### Scenario: Features & roadmap renders in the foldout
<!-- @e2e exclude Menu layout; pinned by the manifest unit test against the drawn foldout of learniq/LqSchooljaar, not a browser-observable behaviour of its own. -->
- **GIVEN** an authenticated user opens the Learniq app
- **WHEN** they open the settings foldout
- **THEN** "Functies en roadmap" appears under "Over de app", next to "Marktplaats"
- **AND** the footer of the sidebar shows only "Hulp en uitleg"

### Requirement: School-year rollover lives in the Settings foldout
The `Rollover` entry (route `RolloverWizard`, admin-gated) MUST be added to `src/menu-layout.json#settingsSection` so it renders inside the settings foldout rather than as a top-level item. The foldout MUST show it as "Jaarovergang" under "De app inrichten".

#### Scenario: Rollover renders in the settings foldout for an admin
<!-- @e2e exclude Menu layout; pinned by the manifest unit test against the drawn foldout of learniq/LqSchooljaar, not a browser-observable behaviour of its own. -->
- **GIVEN** an authenticated admin user opens the Learniq app
- **WHEN** they open the settings foldout
- **THEN** "Jaarovergang" appears under "De app inrichten"
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

### Requirement: The sidebar shows the items drawn for the signed-in role

The main sidebar SHALL show, for each primary role, exactly the items its board draws, in three blocks: an unlabelled top block, the group "Onderwijs" and, where drawn, the group "Leerlingen". Footer and settings-foldout items are governed separately and are not part of this list.

| Role | Top block | Onderwijs | Leerlingen |
|---|---|---|---|
| teacher, study adviser | Vandaag, Mijn leren, Groepen | Mijn rooster, Lessen en opdrachten, Cijfers | Leerlingen, Aanwezigheid, Voortgang, Zorg en dossier |
| coordinator | Vandaag, Mijn leren, Groepen | Mijn rooster, Roosters, Lessen en opdrachten | Leerlingen, Aanwezigheid, Voortgang, Zorg en dossier |
| learner | Vandaag, Mijn leren | Mijn rooster, Cursuscatalogus, Mijn leerdossier, Inchecken, Mijn werkgroepen, Mijn evaluaties, Keuzelessen, Vakken kiezen | none |
| guardian | Vandaag, Leren van <child> | Rooster van <child>, Leerdossier van <child>, Vakken kiezen, Gesprek boeken | none |
| compliance officer | Vandaag, Mijn leren | Mijn rooster, Roosters, Cursuscatalogus, Mijn leerdossier | Compliance |
| team lead, manager | Vandaag, Mijn leren | Mijn rooster, Roosters, Cursuscatalogus, Mijn leerdossier | Voortgang, Aanmeldverzoeken |
| hr, administration, admin | Vandaag, Mijn leren | Mijn rooster, Cursuscatalogus, Mijn leerdossier | Leerlingen, Aanwezigheid, Personen, Compliance |
| confidential counsellor, care coordinator | Vandaag, Mijn leren | Mijn rooster, Mijn leerdossier | Vertrouwelijke notities, Vertrouwelijke meldingen |

#### Scenario: A teacher's sidebar holds ten items in three blocks

@e2e exclude Menu layout; pinned by the manifest unit test against the drawn sidebar of learniq/LqDashboard, not a browser-observable behaviour of its own.
- GIVEN the effective manifest computed by `buildManifest()` for a teacher
- WHEN the main sidebar items (not footer, not settings) are counted
- THEN there are exactly 10, in the blocks and order of the table's teacher row

#### Scenario: GroupInsight no longer exists

- GIVEN the effective manifest computed by `buildManifest()`
- WHEN the menu tree is searched for an id `GroupInsight`
- THEN it is absent

### Requirement: The Dashboard Entry Resolves by Role Instead of Three Separate Rows

The top-level nav SHALL present exactly one dashboard entry, "Vandaag", routed to the existing role-aware `Dashboard` page (route `/`, component `ScholiqDashboards`). The three previously-separate role-gated rows (`DashboardAdmin`/"Administration", `DashboardTeacher`/"Teaching", `DashboardStudent`/"My learning") SHALL NOT appear in the nav; their pages SHALL remain routable at their existing routes (`/dashboards/admin`, `/dashboards/teaching`, `/dashboards/my-learning`) for deep links and e2e targets.

#### Scenario: One Dashboard entry replaces three role rows

- GIVEN an admin, a teacher, and a learner, each viewing the top-level nav
- WHEN they look for a dashboard entry
- THEN each sees exactly one "Vandaag" item (not three, not zero), landing on the shared role-aware `ScholiqDashboards` component

#### Scenario: Retired dashboard leaves stay deep-linkable

- GIVEN a bookmark to `/dashboards/admin`
- WHEN it is opened directly (not via nav click-through)
- THEN the page renders normally — the route was never removed from `pages[]`, only its nav leaf

### Requirement: The sidebar has one level of grouping

The sidebar SHALL group items one level deep: a group label ("Onderwijs", "Leerlingen") with flat items under it. A former group that has its own `children[]` (for example `GroupEngagement`, `GroupAdmissions`) SHALL NOT render as a nested sub-group or as a list of its former children in the sidebar. Its pages SHALL stay routable and SHALL be reached from the page of the drawn item that covers them, for example "Voortgang" for the engagement and study-progress pages, or "Aanmeldverzoeken" for admissions.

#### Scenario: No sidebar item has children of its own

@e2e exclude Menu layout; pinned by the manifest unit test against the drawn sidebar of learniq/LqDashboard, not a browser-observable behaviour of its own.
- GIVEN the effective manifest after this change
- WHEN the items under "Onderwijs" and "Leerlingen" are inspected
- THEN none of them has a `children[]` array

#### Scenario: A former engagement page still opens

@e2e exclude Menu layout; pinned by the manifest unit test against the drawn sidebar of learniq/LqDashboard, not a browser-observable behaviour of its own.
- GIVEN the sidebar shows no item for `Leaderboard`
- WHEN a user opens its route directly
- THEN the page renders without error

### Requirement: No Page or Route Is Dropped by the Six-Group Collapse

Per ADR-044 §5, this change SHALL NOT remove any `pages[]` entry or make any previously-routable route unreachable. Every one of the 277 pages present before this change SHALL still resolve after it, whether or not a menu leaf still points at it directly.

#### Scenario: Full page-id set is unchanged

- GIVEN the effective `pages[]` array before this change
- AND the effective `pages[]` array after this change
- WHEN the two sets of page ids are compared
- THEN they are identical — same 277 ids, same routes, same components
- @e2e exclude Build-time verification script — see test-plan.md

#### Scenario: Every pre-change deep link still resolves

- GIVEN the existing Gate-19 e2e route table (`tests/e2e/pages.spec.ts`)
- WHEN it is run unmodified against the post-change build
- THEN every route resolves with no 404 and no missing-component error

### Requirement: Manifest Content Lives in Boundary-Scoped Fragments, Not the Monolith

`src/manifest.json` MUST carry only `$schema`, `version`, `dependencies`, `observability`, `deepLinks`, and menu/page entries that do not belong to one of the fourteen named `src/manifest.d/*.json` fragment boundaries (`dashboard`, `learning`, `people`, `progress`, `compliance`, `my-learning`, `work-placement`, `guardian-meetings`, `admissions`, `pupil-record`, `assessment-board`, `progress-decisions`, `data-exchange`, `payments`). Every top-level `menu[]` entry that belongs to one of the fourteen boundaries, together with the full `pages[]` entries it (or its children) reference, MUST live in exactly one fragment file — a single node's `children[]` array MUST NOT be split across two fragment files, because `buildManifest`'s fragment merge order depends on `require.context`'s sorted filenames and a cross-file split of one node's children risks silently reordering the rendered menu.

#### Scenario: A target-group fragment owns a full top-level subtree

- GIVEN the `learning.json` fragment
- WHEN it declares the `GroupLearning` menu entry
- THEN it MUST include `GroupLearning`'s complete `children[]` array (all leaf entries, in their original order) in that single file, and no other fragment or the base manifest MAY also declare a `children` array for `GroupLearning`

#### Scenario: A leaving-app module is isolated to one file

- GIVEN the `data-exchange.json` fragment holding the `GroupDataExchange` menu entry and its associated pages
- WHEN a future change deletes Scholiq's in-app data-exchange surface (per `openconnector-flow-migration`)
- THEN deleting `src/manifest.d/data-exchange.json` alone MUST remove the group's menu entry and all its pages from the effective manifest, with no residual edits required in `src/manifest.json`, `src/menu-layout.json`, or any other fragment

### Requirement: Splitting the Manifest Into Fragments Is a No-Behaviour-Change Refactor

The effective manifest produced by `buildManifest(base, fragments, menuLayout)` after the fourteen-fragment split MUST be deep-equal to the effective manifest produced by the same function before the split — same top-level menu ids in the same order, same `children[]` order at every level, same `pages[]` entries (id, route, type, component, config) with no additions, removals, or reorderings. This MUST be verified by an actual computed diff of the two merged manifests, not by manual inspection or "the app still renders."

#### Scenario: Pre/post split diff is empty

- GIVEN the effective manifest computed from the pre-split tree (monolithic `manifest.json` + the 2 legacy fragments)
- AND the effective manifest computed from the post-split tree (skeleton `manifest.json` + the 14 new fragments)
- WHEN the two are deep-equal-compared (menu tree structure and order, full pages array)
- THEN the diff MUST be empty
- @e2e exclude Build-time/CI verification script, not a browser-observable behaviour — see test-plan.md

#### Scenario: Live nav is visually unchanged

- GIVEN an admin user viewing the Scholiq nav before this change ships
- AND the same admin user viewing the Scholiq nav after this change ships
- WHEN comparing the rendered top-level groups, their order, and each group's children
- THEN the two views MUST be identical — no group, leaf, or route appears, disappears, or moves

### Requirement: Every role that reads absence reports reaches them from the menu
The menu MUST offer the absence reports list (`ExcuseRequests`) exactly once to every primary role whose group `ExcuseRequest` grants read (`instructor`, `coordinator`, `administration-manager`, `compliance-officer`) and to `admin`, and to no other role. The entry MUST stay visible on every install that did not choose the `corporate` segment.

#### Scenario: A group teacher opens the absence reports from the menu
@e2e exclude Menu gating; pinned by tests/unit-js/absenceReportsMenu.test.mjs (every role that reads absence reports finds them in the menu, once), built with the library's buildManifest and visibleIf evaluator.
- **GIVEN** `po-leerkracht-09` is a group teacher in a primary school
- **WHEN** they open "Aanwezigheid" in the group "Leerlingen"
- **THEN** it lists "Absence reports", which opens `/attendance/excuses`

#### Scenario: A compliance officer finds the reports under Compliance
@e2e exclude Menu gating; pinned by tests/unit-js/absenceReportsMenu.test.mjs (every role that reads absence reports finds them in the menu, once).
- **GIVEN** a compliance officer, whose group "Leerlingen" holds only "Compliance"
- **WHEN** they open "Compliance"
- **THEN** it lists "Absence reports"

#### Scenario: Roles without read access get no entry
@e2e exclude Menu gating; pinned by tests/unit-js/absenceReportsMenu.test.mjs (roles the register does not let read a report get no entry; every school segment shows the entry).
- **GIVEN** a user whose primary role is `hr`, `team-lead`, `learner` or `guardian`, or a school that chose `corporate`
- **WHEN** they open the menu
- **THEN** no entry opens the absence reports
