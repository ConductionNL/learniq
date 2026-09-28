---
slug: dashboard
title: Role-Aware Dashboards
status: done
feature_tier: must
depends_on_adrs: [adr-001, adr-003, adr-012, adr-009]   # TODO until ADRs land
created: 2026-05-11
openspec_changes:
  - fix-dashboards-settings-notifications
---

# Role-Aware Dashboards

## Purpose

Present per-role dashboard surfaces (ADR-009 §6) as **three group-gated menu items** — Administration / Teaching / My learning — each routing to the shared dashboard component in its role view (admin / teacher / student); menu visibility follows `scholiq-{role}` Nextcloud group membership (admins see all three) and there is no in-page role switcher. Built exclusively on `@conduction/nextcloud-vue` dashboard primitives. Exactly one `CnDashboardPage` renders per route (no dashboard-in-dashboard nesting), and heavy cross-tenant analytics deep-link into launchpad rather than being reimplemented.

## Why
"Analytics dashboard" (#17, 39 demand) and "Student Analytics" (#18, 34 demand) score in the top 20 canonical features. Insight #16: OSS LMS leaders share dated UX — a modern Vue / NL-Design dashboard surface is the structural differentiator. Eight stories across six roles (mentor pattern view, manager team progress, board compliance %, board renewal report, principal Cito overview, parent grade digest, pupil grade-impact view, civil-servant RADIO progress) anchor this spec.

## What
Per-role landing dashboards composed via `@conduction/nextcloud-vue` primitives (CnDashboardPage, CnIndexPage) over Pinia stores reading OpenRegister + GraphQL: **teacher** (cohort distribution, soft-publish queue), **student/pupil** (next exams, grade impact, RADIO progress), **parent** (digest preferences, OPP signing tasks, sick reports), **HR/manager** (team learning progress, time-to-competence), **compliance officer / board** (live coverage % per regulation, NIS2 board proof, renewal status), **inspector / principal** (Cito overview per leerjaar, audit log access). Heavier analytics (cross-tenant trends) delegate to launchpad.

## User Stories
- As a mentor, I want a dashboard with absence patterns of my mentor class so I can spot a pupil with rising absence early.
- As a manager, I want one dashboard with each report row showing assigned, in-progress, completed, overdue counts plus a heat-map by skill area.
- As a board member, I want live coverage % per regulation (BIO, AVG, NIS2, integriteit) with red/amber/green bands and 12-month trend.
- As a school principal, I want to export an inspectie-ready overview of Cito results per leerjaar to demonstrate basisvaardigheden to the Onderwijsinspectie.
- As a pupil, I want to see each new grade together with its weight and impact on my period average so I understand what to focus on next.

## Acceptance Criteria
- GIVEN a user logs in, WHEN their role resolves, THEN the matching dashboard layout loads as the default route (no manual selection).
- GIVEN a board member opens the compliance dashboard, WHEN data loads, THEN coverage % per regulation renders with red/amber/green bands within 2 seconds.
- GIVEN a manager opens the team tab, WHEN the page loads, THEN every report row shows assigned/in-progress/completed/overdue counts plus a skill-area heat map.
- GIVEN heavier cross-tenant analytics are needed, WHEN the user requests them, THEN the dashboard deep-links into launchpad (single-sign-on session shared).

## Requirements

### Requirement: Per-role group-gated dashboard menu items
The system MUST present a **separate top-level dashboard menu item per role** — Administration (admin), Teaching (teacher), My learning (student) — per ADR-009 §6. Each item MUST be visible only to users whose resolved dashboard-view set includes that role, derived server-side from `scholiq-{role}` Nextcloud group membership (with the NC admin group short-circuiting to all three); an admin therefore sees all three items, and the view set always includes `student` so every Scholiq user reaches their own My-learning view. The system MUST NOT render an in-page role switcher. Each menu item routes to the shared dashboard component rendering that role's view (exactly one `CnDashboardPage` per route). The application root route MUST land each user on the dashboard view matching their resolved `primaryRole`; when the role cannot be resolved the system MUST fall back to the least-privileged (student) view.

#### Scenario: Learner sees only the My learning item and lands on it
- **GIVEN** a signed-in user in the `scholiq-student` group only
- **WHEN** they open the Scholiq app
- **THEN** the navigation shows a single **My learning** dashboard item (no Administration or Teaching item)
- **AND** the app root lands on the student dashboard (my enrolments, my grades, due assignments, mandatory training)
<!-- @e2e exclude Group-gated nav visibility requires provisioning a `scholiq-student`-only Nextcloud user and logging in as them; the scholiq e2e harness runs a single admin session and cannot switch group membership per test. Verified live instead. -->

#### Scenario: Instructor sees Teaching + My learning
- **GIVEN** a signed-in user in the `scholiq-teacher` group
- **WHEN** they open the navigation
- **THEN** a **Teaching** item and a **My learning** item are shown (no Administration item)
- **AND** the Teaching item renders the teacher dashboard (my courses, assignments to grade, sessions to mark, my cohorts)
<!-- @e2e exclude Requires a `scholiq-teacher` group member session (multi-user / per-test group membership) that the single-admin scholiq e2e harness cannot provision. Verified live instead. -->

#### Scenario: Admin sees all three dashboard items
- **GIVEN** a signed-in user in the Nextcloud admin group
- **WHEN** they open the navigation
- **THEN** **Administration**, **Teaching** and **My learning** items are all shown
- **AND** each routes to its own dashboard view without any in-page role switcher
<!-- @e2e exclude Asserts three group-gated nav items are simultaneously visible to an NC-admin-group member; the admin short-circuit is a server-side group resolution not reproducible as a pure scholiq DOM flow in the current e2e harness. Verified live instead. -->

### Requirement: Use @conduction/nextcloud-vue dashboard components
The system MUST use `@conduction/nextcloud-vue` dashboard components (`CnDashboardPage` et al.) — no custom equivalents. A `type: "dashboard"` manifest page MUST declare its tiles directly in `config.widgets` / `config.layout` / `slots`, each slot resolving to a plain widget component (KPI card, list, chart). A dashboard page or any widget component it hosts MUST NOT render a nested `CnDashboardPage` (the dashboard-in-dashboard antipattern); exactly one `CnDashboardPage` MUST render per dashboard route.

#### Scenario: Single CnDashboardPage per route
- **GIVEN** the Scholiq dashboard route is rendered
- **WHEN** the component tree is inspected
- **THEN** exactly one `CnDashboardPage` is present and the page heading appears once

#### Scenario: Widgets declared on the manifest page
- **GIVEN** the manifest `Dashboards` page
- **WHEN** its `config` is read
- **THEN** each KPI / manage tile is a distinct entry in `config.widgets` with a matching `slots["widget-<id>"]` mapping to its own widget component, and there is no single wrapper widget that re-renders the whole dashboard

### Requirement: Delegate heavy analytics to launchpad via deep links
The system MUST delegate cross-tenant or heavy-aggregation analytics to launchpad via deep links rather than reimplementing.

#### Scenario: Heavy analytics deep-link to launchpad
<!-- @e2e exclude Cross-app deep-link into launchpad; launchpad is not provisioned in the scholiq e2e environment, so the target cannot be driven. The non-reimplementation guardrail is a structural review concern, not a scholiq DOM behaviour. -->
- **GIVEN** a user viewing a Scholiq dashboard that surfaces a cross-tenant or heavy-aggregation analytics affordance
- **WHEN** the user requests that analytics view
- **THEN** the dashboard deep-links into launchpad (shared single-sign-on session) rather than rendering a Scholiq-local cross-tenant aggregation

### Requirement: Learning domain dashboard
The system MUST provide a `LearningDashboard` page (component `LearningDashboard`, route `/learning`) rendered as exactly one `CnDashboardPage`. It MUST surface the learning domain's KPIs (courses, curriculum/programmes, assignments, assessments, grades) as KPI tiles and MUST offer manage-list entry points into the learning leaves (e.g. courses, assignments). It MUST reuse existing `src/views/widgets/*` components (KPI cards, `ManageListWidget`) rather than custom equivalents, and MUST NOT be rendered as a widget inside another dashboard.

#### Scenario: Learning dashboard renders one CnDashboardPage with learning KPIs
- **GIVEN** an authenticated user navigates to `/learning`
- **WHEN** the `LearningDashboard` renders
- **THEN** exactly one `CnDashboardPage` is present and its heading appears once
- **AND** learning-domain KPI tiles (e.g. courses, assignments, assessments, grades) are shown as distinct widgets

#### Scenario: Learning dashboard manage-lists link into the learning leaves
- **GIVEN** the user is on the `LearningDashboard`
- **WHEN** they use a manage-list entry point (e.g. the courses list)
- **THEN** the browser navigates to the corresponding learning leaf route (e.g. `/courses`)

#### Scenario: Learning dashboard is not a nested dashboard
<!-- @e2e exclude Structural/anti-pattern assertion — enforced by the hydra dashboard-antipattern gate and the manifest/component unit tests (LearningDashboard is a page component, never referenced as a widget slot on another dashboard); not a positive DOM behaviour distinct from the single-CnDashboardPage scenario above. -->
- **GIVEN** the `LearningDashboard` component tree
- **WHEN** it is inspected
- **THEN** it renders a single `CnDashboardPage` and no widget it hosts renders a nested `CnDashboardPage`

### Requirement: People domain dashboard
The system MUST provide a `PeopleDashboard` page (component `PeopleDashboard`, route `/people`) rendered as exactly one `CnDashboardPage`. It MUST surface the people domain's KPIs (learners, enrolments, attendance, credentials) as KPI tiles and MUST offer manage-list entry points into the people leaves (e.g. learners, enrolments). It MUST reuse existing `src/views/widgets/*` components rather than custom equivalents, and MUST NOT be rendered as a widget inside another dashboard.

#### Scenario: People dashboard renders one CnDashboardPage with people KPIs
- **GIVEN** an authenticated user navigates to `/people`
- **WHEN** the `PeopleDashboard` renders
- **THEN** exactly one `CnDashboardPage` is present and its heading appears once
- **AND** people-domain KPI tiles (e.g. learners, enrolments, attendance, credentials) are shown as distinct widgets

#### Scenario: People dashboard manage-lists link into the people leaves
- **GIVEN** the user is on the `PeopleDashboard`
- **WHEN** they use a manage-list entry point (e.g. the learners list)
- **THEN** the browser navigates to the corresponding people leaf route (e.g. `/learner-profiles`)

### Requirement: Every manifest role-visibility literal MUST resolve to a value the role resolver can emit

`DashboardRoleService::resolvePrimaryRole()` is the single source of `runtime.user.primaryRole`, the value every
`src/manifest.json` `visibleIf.user.primaryRole.in[]` gate is evaluated against. The system MUST guarantee that
the resolver's set of producible values is a superset of every role literal named across every `visibleIf`
gate in the app's effective manifest (base + `manifest.d/*.json` fragments). A `visibleIf` predicate is
fail-safe by construction — any mismatch hides the menu entry rather than erroring — so an unproducible literal
is a silent access-control misrepresentation, not a visible bug: the menu claims delegated access it can never
actually grant. The resolver MUST derive role membership from Nextcloud group membership using the unprefixed
group ids declared by the app's RBAC scope-map configuration (never a `scholiq-`-prefixed convention that no
declaration provisions), checked admin-first. The producible role vocabulary MUST be the canonical,
product-neutral set (`learner`, `instructor`, `team-lead`, `coordinator`, `hr`, `compliance-officer`,
`guardian`, `administration-manager`) shared with the `rbac-declare-groups` change — school-specific words
(`teacher`, `principal`, `mentor`, `parent`) MUST NOT appear as `visibleIf` literals or resolver return values.

#### Scenario: An instructor-group member sees the Learning-analytics trend heatmap
- **GIVEN** a signed-in user who is a member of the `instructors` Nextcloud group and no other privileged group
- **WHEN** they open the Scholiq navigation
- **THEN** the Group Trend Heatmap menu item is visible
- **AND** opening it renders the heatmap for their own cohorts
<!-- @e2e exclude Group-gated nav visibility requires provisioning an `instructors`-only Nextcloud user and logging in as them; the scholiq e2e harness runs a single admin session and cannot switch group membership per test. Verified live instead — see test-plan.md TC-1 for who performs this verification and what is recorded. -->

#### Scenario: A coordinator-group member sees the Engagement configuration items
- **GIVEN** a signed-in user who is a member of the `coordinators` Nextcloud group and no other privileged group
- **WHEN** they open the Scholiq navigation
- **THEN** Point Rules, Engagement Levels, Leaderboards, Point Awards, Engagement Risk Thresholds, and Timetable
  Conflict Queue are all visible
<!-- @e2e exclude Requires a `coordinators` group member session the single-admin scholiq e2e harness cannot provision. Verified live instead — see test-plan.md TC-2. -->

#### Scenario: A guardian-group member sees Book Conference Slots but no staff-only item
- **GIVEN** a signed-in user who is a member of the `guardians` Nextcloud group and no other privileged group
- **WHEN** they open the Scholiq navigation
- **THEN** the Book Conference Slots menu item is visible under Conferences
- **AND** none of the staff-only items (Payments group, Data-exchange group, Engagement group, Compliance,
  Group Trend Heatmap, Engagement Risk Thresholds, Course Evaluation Responses, Conference Schedule Board,
  Timetable Conflict Queue) are visible
<!-- @e2e exclude Requires a `guardians` group member session the single-admin scholiq e2e harness cannot provision. Verified live instead — see test-plan.md TC-3. -->

#### Scenario: A learner with no privileged group membership sees exactly the baseline set
- **GIVEN** a signed-in user with the `learner` role and no membership in `instructors`, `coordinators`,
  `team-leads`, `guardians`, `hr`, `compliance-officers`, or `administration-managers`, and not in the
  Nextcloud admin group
- **WHEN** they open the Scholiq navigation
- **THEN** of the 24 `visibleIf.user.primaryRole`-gated menu items, exactly one is visible — Book Conference
  Slots, via its `learner` literal — and the other 23 are individually confirmed absent
- **AND** My learning remains visible (governed by the separate group-gated dashboard requirement below, not by
  `primaryRole`)
<!-- @e2e exclude Asserts a negative — absence of 23 specific menu entries for a specific group-membership state — which needs a dedicated non-privileged Nextcloud user the single-admin scholiq e2e harness cannot provision. Verified live instead — see test-plan.md TC-4, which names the verifier and requires the exact count and per-item confirmation, not a general "absent" claim. -->

### Requirement: Administrators MUST retain access to every role-gated menu item

A `visibleIf.user.primaryRole.in[]` gate that omits `admin` is unreachable by the one role Nextcloud always
guarantees exists, on every installation, from first boot. The system MUST include `admin` in the `in[]` list
of every menu item gated on `user.primaryRole`, with no exception, so that an administrator can always reach
every feature the app ships regardless of which delegated roles are or are not yet populated with users.

#### Scenario: Admin sees the Compliance item
- **GIVEN** a signed-in user in the Nextcloud admin group
- **WHEN** they open the Scholiq navigation
- **THEN** the Compliance menu item is visible under Insight
- **AND** opening it renders `/apps/scholiq/compliance` with the seeded regulation, attestation, and
  external-training coverage data
<!-- @e2e exclude Server-side admin-group resolution feeding `runtime.user.primaryRole`; verified live on the shared dev instance rather than reproduced as a scholiq DOM-only e2e flow — see test-plan.md TC-5. -->

#### Scenario: Admin sees Book Conference Slots
- **GIVEN** a signed-in user in the Nextcloud admin group
- **WHEN** they open the Scholiq navigation
- **THEN** the Book Conference Slots menu item is visible under Conferences
<!-- @e2e exclude Server-side admin-group resolution feeding `runtime.user.primaryRole`; verified live on the shared dev instance rather than reproduced as a scholiq DOM-only e2e flow — see test-plan.md TC-6. -->

### Requirement: A CI gate MUST reject a manifest role literal the resolver cannot emit, and a group name no declaration provisions

Because a `visibleIf` mismatch is silent in the running app, the guarantee in the two requirements above MUST
be enforced mechanically before merge, not only by manual review. The fleet's manifest cross-reference gate
MUST fail a pull request that either (a) introduces or leaves a `visibleIf.user.primaryRole.in[]` literal in
the effective manifest that the app's role resolver cannot emit, or (b) introduces or leaves an
`IGroupManager::isInGroup()` call site naming a group id that is not declared anywhere the app's RBAC group
collector reads (its OAS scope map or `authorization` blocks).

#### Scenario: Gate fails on a role literal the resolver cannot produce
- **GIVEN** a pull request adds `"in": ["admin", "auditor"]` to a manifest `visibleIf.user.primaryRole` gate
- **AND** `DashboardRoleService::resolvePrimaryRole()` has no path that can return `"auditor"`
- **WHEN** the manifest cross-reference gate runs against the PR diff
- **THEN** the gate reports a `role-resolvable` finding naming the gate's menu item id and the unproducible
  literal
- **AND** the gate's overall status is `failed`

#### Scenario: Gate fails on a group name no declaration provisions
- **GIVEN** a pull request adds a new `isInGroup($uid, 'auditors')` call to `DashboardRoleService`
- **AND** no register/schema `authorization` block or OAS scope map anywhere in the app declares the group id
  `auditors`
- **WHEN** the manifest cross-reference gate runs against the PR diff
- **THEN** the gate reports a `group-declared` finding naming the call site and the undeclared group id
- **AND** the gate's overall status is `failed`

#### Scenario: Gate passes when every literal and every group are accounted for
- **GIVEN** a pull request's manifest names only role literals `DashboardRoleService::resolvePrimaryRole()` can
  emit, and every `isInGroup()` call site names a group declared in the app's RBAC configuration
- **WHEN** the manifest cross-reference gate runs against the PR diff
- **THEN** the `role-resolvable` and `group-declared` checks both report zero findings
- **AND** the gate's overall status is `passed`

## Standards
NL Design System, WCAG 2.1 AA, Schema.org `Dataset` / `Observation`, Caliper Analytics for event source.

## Data Model
See `docs/ARCHITECTURE.md`. Uses: `RoleAssignment`, `DashboardPreference`. Reads from every other spec's entities via OpenRegister read-only views.

## Out of Scope
- Custom-report builder (V2; launchpad territory).
- Cross-tenant benchmarking / sectoraal vergelijken (launchpad + Specter feeds).
- Mobile-native apps (responsive Vue is MVP).
