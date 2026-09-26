# dashboard Specification

## ADDED Requirements

### Requirement: Mentor, IB-er, and director each get a named, role-gated entry point to the operational dashboard

The system MUST present three additional nav entries under the existing dashboard menu group:
`DashboardMentorMenu` (label "Mentor"), `DashboardIbMenu` (label "IB-er"), and
`DashboardDirectorMenu` (label "Director"), each routing to the existing `DashboardTeacher` page.
Each entry MUST be gated on `user.primaryRole` via the already-resolved, already-exposed
`primaryRole` runtime value (`DashboardRoleService::resolvePrimaryRole()`), NOT a new
`canXDashboard` boolean: `DashboardMentorMenu` on `instructor`/`admin`, `DashboardIbMenu` on
`coordinator`/`admin`, `DashboardDirectorMenu` on `administration-manager`/`admin` — mirroring the
existing `Compliance`/`AiProcessingDisclosure` entries' own `user.primaryRole`-gated pattern in the
same manifest file. These entries render the SAME operational dashboard content
`DashboardTeacher`/`LearniqDashboards.vue` already ships (distinct per-role widget content is
explicitly out of scope for this requirement — see the change's proposal.md).

#### Scenario: An instructor sees the Mentor entry point

<!-- @e2e exclude Group-gated nav visibility requires provisioning a role-specific Nextcloud user and logging in as them; the learniq e2e harness runs a single admin session and cannot switch group/role membership per test, mirroring the existing dashboard spec's own established exclusion pattern for this exact class of scenario. Verified by reasoning over the built effective manifest (build_effective_manifest.js) instead. -->

- **GIVEN** a signed-in user whose resolved `primaryRole` is `instructor`
- **WHEN** they open the navigation
- **THEN** a **Mentor** nav entry is shown, routing to the same page the existing **Teaching**
  entry routes to

#### Scenario: A coordinator sees the IB-er entry point, and an administration-manager sees the Director entry point

<!-- @e2e exclude Same scope boundary as the scenario above — role-specific session provisioning is not available in this app's e2e harness. Verified by reasoning over the built effective manifest instead. -->

- **GIVEN** a signed-in user whose resolved `primaryRole` is `coordinator`
- **WHEN** they open the navigation
- **THEN** an **IB-er** nav entry is shown
- **GIVEN** a signed-in user whose resolved `primaryRole` is `administration-manager`
- **WHEN** they open the navigation
- **THEN** a **Director** nav entry is shown

#### Scenario: An admin sees all three new entries

<!-- @e2e exclude Same scope boundary — role-specific session provisioning is not available in this app's e2e harness. Verified by reasoning over the built effective manifest instead. -->

- **GIVEN** a signed-in user in the Nextcloud admin group
- **WHEN** they open the navigation
- **THEN** **Mentor**, **IB-er**, and **Director** entries are all shown, alongside the existing
  **Administration**, **Teaching**, and **My learning** entries
