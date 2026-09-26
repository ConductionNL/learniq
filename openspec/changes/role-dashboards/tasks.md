# Tasks: role-dashboards

## Implementation Tasks

### Task 1: Add DashboardMentorMenu, DashboardIbMenu, DashboardDirectorMenu nav entries
- **spec_ref**: `openspec/changes/role-dashboards/specs/dashboard/spec.md#requirement-mentor-ib-er-and-director-each-get-a-named-role-gated-entry-point-to-the-operational-dashboard`
- **files**: `src/manifest.d/dashboard.json`
- **acceptance_criteria**:
  - GIVEN a user with `primaryRole: instructor` WHEN they open the nav THEN a "Mentor" entry is shown, routing to the same page as "Teaching"
  - GIVEN a user with `primaryRole: coordinator` WHEN they open the nav THEN an "IB-er" entry is shown
  - GIVEN a user with `primaryRole: administration-manager` WHEN they open the nav THEN a "Director" entry is shown
  - GIVEN an admin user WHEN they open the nav THEN all three new entries are shown alongside the existing three
- [x] Implement
- [x] Test

## Quality checklist

- No new PHP/Vue file — nothing to unit-test beyond the manifest itself
- Verified via `npm run check:specs` (manifest/menu-role-gates) and the shared
  `build_effective_manifest.js`/`check_manifest_crossref.js` libraries
- Dutch (`nl_NL`) and English (`en_US`) translation strings added for the three new labels (ADR-007)
- `openspec validate --change role-dashboards` passes
