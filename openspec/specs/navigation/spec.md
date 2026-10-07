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

### Requirement: The Top-Level Nav Presents Six Named Destinations Plus Two Pending Extractions

Scholiq's main navigation SHALL present exactly the following top-level destinations after this change: `Dashboard`, `Learning`, `People`, `Progress`, `Compliance`, `My learning`, `Data exchange`, `Payments`. No other top-level main-nav item SHALL exist (footer and settings-foldout items are governed separately, unchanged by this requirement). `Data exchange` and `Payments` remain only because their extraction is owned by other changes (`openconnector-flow-migration` and a future pipelinq change respectively) — a future change that completes both extractions SHALL reduce this list to six without requiring a `MODIFIED` to this requirement's structure, only to the enumerated list.

#### Scenario: Exactly eight top-level main-nav items post-change

- GIVEN the effective manifest computed by `buildManifest()` after this change
- WHEN the top-level `menu[]` entries with `section` unset (i.e. not footer, not settings) are counted
- THEN there are exactly 8: `Dashboard`, `Learning`, `People`, `Progress`, `Compliance`, `My learning`, `Data exchange`, `Payments`

#### Scenario: GroupInsight no longer exists

- GIVEN the effective manifest computed by `buildManifest()` after this change
- WHEN the menu tree is searched for an id `GroupInsight`
- THEN it is absent — its 8 children have all been relocated (Dashboard leaves retired, Compliance-flavoured leaves moved under the new `Compliance` group), and `buildManifest`'s existing empty-shell pruning drops the now-childless node

### Requirement: The Dashboard Entry Resolves by Role Instead of Three Separate Rows

The top-level nav SHALL present exactly one `Dashboard` entry, routed to the existing role-aware `Dashboard` page (route `/`, component `ScholiqDashboards`). The three previously-separate role-gated rows (`DashboardAdmin`/"Administration", `DashboardTeacher`/"Teaching", `DashboardStudent`/"My learning") SHALL NOT appear in the nav; their pages SHALL remain routable at their existing routes (`/dashboards/admin`, `/dashboards/teaching`, `/dashboards/my-learning`) for deep links and e2e targets.

#### Scenario: One Dashboard entry replaces three role rows

- GIVEN an admin, a teacher, and a learner, each viewing the top-level nav
- WHEN they look for a dashboard entry
- THEN each sees exactly one `Dashboard` item (not three, not zero), landing on the shared role-aware `ScholiqDashboards` component

#### Scenario: Retired dashboard leaves stay deep-linkable

- GIVEN a bookmark to `/dashboards/admin`
- WHEN it is opened directly (not via nav click-through)
- THEN the page renders normally — the route was never removed from `pages[]`, only its nav leaf

### Requirement: Groups-With-Children Preserve Their Identity When Folded Into a New Parent

When a source top-level group that itself has `children[]` (e.g. `GroupEngagement`, `GroupAdmissions`) is folded into one of the six named destinations, its own label and identity SHALL be preserved as a nested, labeled sub-group of the new parent — never flattened into an undifferentiated sibling list of its former children. This is achieved by declaring the desired nested structure directly in the owning `manifest.d/*.json` fragment(s) rather than via `menu-layout.json#relocations` (which dissolves a relocated group by design — see design.md for the source-verified mechanism).

#### Scenario: Engagement survives as a labeled sub-group under Progress

- GIVEN the effective manifest after this change
- WHEN the `GroupProgress` node's children are inspected
- THEN `GroupEngagement` (with its own label "Engagement" and its own 6 children `Leaderboard`/`Point rules`/`Levels`/`Leaderboards (config)`/`Point awards`/`Learner engagement`) appears as one nested child — not as 6 ungrouped siblings mixed with the other five folded groups' children

#### Scenario: A flattened list is never produced for a many-child fold

- GIVEN any of the seven groups folded into `GroupProgress` (Engagement, Course evaluation, Competencies, Progress & analytics, Portfolios, Study progress, BPV)
- WHEN the effective `GroupProgress.children` array is inspected
- THEN it has exactly 7 entries (one per folded group), not the ~28 that a flattening merge would produce

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
- **WHEN** they open the People group in the menu
- **THEN** it lists "Absence reports", which opens `/attendance/excuses`

#### Scenario: A compliance officer finds the reports under Compliance
@e2e exclude Menu gating; pinned by tests/unit-js/absenceReportsMenu.test.mjs (every role that reads absence reports finds them in the menu, once).
- **GIVEN** a compliance officer, who does not see the People group
- **WHEN** they open the Compliance group
- **THEN** it lists "Absence reports"

#### Scenario: Roles without read access get no entry
@e2e exclude Menu gating; pinned by tests/unit-js/absenceReportsMenu.test.mjs (roles the register does not let read a report get no entry; every school segment shows the entry).
- **GIVEN** a user whose primary role is `hr`, `team-lead`, `learner` or `guardian`, or a school that chose `corporate`
- **WHEN** they open the menu
- **THEN** no entry opens the absence reports
