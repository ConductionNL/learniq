# Design: role-dashboards

## Architecture Overview
Three new nav-menu entries in `src/manifest.d/dashboard.json`, each gated on `user.primaryRole`
(already resolved server-side by the existing `DashboardRoleService::resolvePrimaryRole()` and
exposed as manifest runtime state — no PHP change) and routing to the existing `DashboardTeacher`
page (`type: "custom"`, mounts `LearniqDashboards.vue role="teacher"`, unchanged). No new page, no
new component, no new backend surface.

## API Design
Not applicable — no endpoint added or changed.

## Database Changes
Not applicable — no schema touched.

## Nextcloud Integration
- Controllers/Services/Mappers: none added or changed.
- Events/Hooks: none.

## Security Considerations
No new read/write surface: the three entries route to a page every `instructor`/`coordinator`/
`administration-manager`/`admin` user could already reach via the existing **Teaching** entry if
they happened to also carry `instructor` primaryRole. This change only adds discoverability
(a role-appropriate label), not access.

## NL Design System
Reuses the existing `DashboardTeacher` page and `LearniqDashboards.vue` component entirely; no new
UI surface.

## File Structure
```
src/
  manifest.d/
    dashboard.json   (MODIFIED — three new nav children: DashboardMentorMenu, DashboardIbMenu, DashboardDirectorMenu)
```

## Declarative-vs-imperative decision (ADR-031)
| Behaviour | Path chosen | Rationale |
|---|---|---|
| Role gating for the three new entries | Declarative (manifest `visibleIf` on `user.primaryRole`) | Identical mechanism to the existing `Compliance`/`AiProcessingDisclosure` entries in the same file; `primaryRole` is already resolved and exposed, no new PHP needed. |

## Seed Data
Not applicable — no new schema, no new data.

## Trade-offs
Considered giving each new entry a distinct widget mix (a real "mentor dashboard" vs "IB-er
dashboard"). Rejected for this change: `DashboardTeacher` is a bespoke Vue component
(`type: "custom"`), and differentiating its content per role is a `code`-shaped change (new Vue
branches or a new wrapper component) that ADR-032 forbids mixing into a `config` spec. Shipping the
discoverable nav entry now, and filing content differentiation as an explicit follow-up, delivers
finding 12.1's discoverability value immediately without inventing unverified behaviour or crossing
the config/code boundary.

Considered also shipping 8.12's mentor-scoping + "today" filter on the "sessions to mark" tile in
the same change. Rejected: `Session` has no `teacherId` field (only `cohortId`; the teacher
relationship lives on `Cohort.teacherIds[]`), so scoping requires a join this manifest's declarative
layer cannot express, and `ManageListWidget`'s `filter` prop passes straight through as raw
query-string params with no verified range-filter grammar anywhere in this codebase. Both are
genuine `code` work with no existing test harness in this repo for Vue component behaviour
(`tests/unit-js` uses plain `node --test`, not a Vue test-utils/vitest setup) to verify a
client-side filter branch before shipping it — the same "unverified operator, silent no-op" risk
`care-and-support-index` (a sibling change) caught and reverted before commit. Filed as follow-up,
not attempted here.

## Open Questions
None outstanding.
