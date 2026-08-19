# Tasks: rbac-declare-groups

## Implementation Tasks

### Task 1: Register-level authorization cascade, named role, and the canonical eight-group scope map
- **spec_ref**: `openspec/changes/rbac-declare-groups/specs/rbac-groups/spec.md#requirement-req-001-the-scholiq-register-declares-a-role-based-authorization-cascade-staff-only`, `openspec/changes/rbac-declare-groups/specs/rbac-groups/spec.md#requirement-req-004-the-registers-oauth2-scope-map-declares-the-canonical-eight-group-ids`
- **files**: `lib/Settings/scholiq_register.json` (`components.registers.scholiq.configuration.roles`, `components.registers.scholiq.authorization`, new top-level `components.securitySchemes.oauth2.flows.authorizationCode.scopes`, currently absent entirely)
- **acceptance_criteria**:
  - GIVEN the `scholiq` register WHEN `configuration.roles` is read THEN it contains exactly one role, `read-write` (`actions: ["read","create","update"]`) — no `read-only` role
  - GIVEN the register's `authorization` block WHEN it is read THEN `roles.read-write` lists `instructors`, `hr`, `compliance-officers`, `team-leads`, no `delete` key is present, and `learners` is named nowhere in the block (C1 — no blanket learner grant)
  - GIVEN the completed scope map WHEN read in isolation THEN it lists all eight canonical ids (`instructors`, `hr`, `compliance-officers`, `team-leads`, `learners`, `coordinators`, `guardians`, `administration-managers`) with descriptions, and neither `admin` nor `public`
- [x] Implement
- [x] Test

### Task 2: Tier-1 authorization blocks — Profiles A and D (10 schemas)
- **spec_ref**: `openspec/changes/rbac-declare-groups/specs/rbac-groups/spec.md#requirement-req-002-21-named-tier-1-schemas-declare-narrow-individually-assigned-authorization`
- **files**: `lib/Settings/scholiq_register.json` (schema-level `authorization` on `DossierNote`, `WellbeingCheckIn`, `BehaviourIncident`, `ExcuseRequest`, `AttendanceRecord`, `AttendanceFlag`, `ExemptionCase`, `ProctoringSession`, `FraudCase`, `LearnerProfile`; remove the pre-existing `x-openregister-authorization` key on `DossierNote`, `BehaviourIncident`, `ProctoringSession`)
- **acceptance_criteria**:
  - GIVEN each of the 9 Profile-A schemas WHEN its `authorization` block is read THEN `read`/`create`/`update` each list exactly `instructors`, `compliance-officers`
  - GIVEN `LearnerProfile` (Profile D) WHEN its `authorization` block is read THEN `read` lists `instructors`, `hr`, `compliance-officers` and `create`/`update` list `hr`, `compliance-officers`
  - GIVEN `DossierNote`, `BehaviourIncident`, `ProctoringSession` WHEN the file is inspected THEN none carries an `x-openregister-authorization` key
- [x] Implement
- [x] Test

### Task 3: Tier-1 authorization blocks — Profiles B and C (11 schemas)
- **spec_ref**: `openspec/changes/rbac-declare-groups/specs/rbac-groups/spec.md#requirement-req-002-21-named-tier-1-schemas-declare-narrow-individually-assigned-authorization`
- **files**: `lib/Settings/scholiq_register.json` (schema-level `authorization` on `TlvApplication`, `BsaDecision`, `BsaWarning`, `BsaTrajectory`, `ConferenceReport`, `PeerReview`, `SelfAssessment`, `Submission`, `Attestation`, `ExternalTrainingRecord`, `Credential`; remove the pre-existing `x-openregister-authorization` key on `TlvApplication`, `BsaDecision`, `BsaWarning`, `PeerReview`)
- **acceptance_criteria**:
  - GIVEN each of the 8 Profile-B schemas WHEN its `authorization` block is read THEN `read`/`create`/`update` each list exactly `compliance-officers`, `team-leads`
  - GIVEN each of the 3 Profile-C schemas WHEN its `authorization` block is read THEN `read`/`create`/`update` each list exactly `hr`, `compliance-officers`
  - GIVEN `TlvApplication`, `BsaDecision`, `BsaWarning`, `PeerReview` WHEN the file is inspected THEN none carries an `x-openregister-authorization` key
- [x] Implement
- [x] Test

### Task 4: Tier-3 catalogue authorization blocks — Profile 3a shared-config (13 schemas) and Profile 3b course-content (8 schemas, new per C5)
- **spec_ref**: `openspec/changes/rbac-declare-groups/specs/rbac-groups/spec.md#requirement-req-003-21-named-tier-3-catalogue-schemas-declare-wide-read-staff-only-write-across-two-profiles`
- **files**: `lib/Settings/scholiq_register.json` (schema-level `authorization` on Profile 3a: `GradeScale`, `ReportPeriod`, `CompetencyFramework`, `PointRule`, `EngagementLevel`, `Leaderboard`, `CourseTemplate`, `PortfolioTemplate`, `LearningPlanTemplate`, `Regulation`, `ExchangeErrorCode`, `Room`, `FeeItem`; and Profile 3b: `Course`, `Lesson`, `Material`, `Programme`, `CurriculumPlan`, `Assignment`, `Assessment`, `ItemBank`; remove the pre-existing `x-openregister-authorization` key on `ReportPeriod`, `Room`)
- **acceptance_criteria**:
  - GIVEN each of the 13 Profile-3a schemas WHEN its `authorization` block is read THEN `read` lists exactly `authenticated` and `create`/`update` each list exactly `compliance-officers`, `team-leads` (`instructors`/`hr` deliberately excluded)
  - GIVEN each of the 8 Profile-3b schemas WHEN its `authorization` block is read THEN `read` lists exactly `authenticated` and `create`/`update` each list exactly `instructors`, `hr`, `compliance-officers`, `team-leads` (`instructors` deliberately INCLUDED — they author this content; C5's fix)
  - GIVEN `ReportPeriod`, `Room` WHEN the file is inspected THEN neither carries an `x-openregister-authorization` key
- [x] Implement
- [x] Test

### Task 5: Remove the remaining decoy `x-openregister-authorization` keys (11 Tier-2 schemas)
- **spec_ref**: `openspec/changes/rbac-declare-groups/specs/rbac-groups/spec.md#requirement-req-006-the-x-openregister-authorization-decoy-key-is-removed-from-every-schema-that-carries-it`
- **files**: `lib/Settings/scholiq_register.json` (delete `x-openregister-authorization` from `SovereigntyPolicy`, `XapiStatement`, `Application`, `ExamAccommodation`, `ReportCard`, `SupportRequest`, `DeliberationRecord`, `EngagementRiskThreshold`, `EngagementRiskFlag`, `AccessibilityStatement`, `AccessibilityLimitation` — no replacement key added, they fall back to the Task 1 register cascade)
- **acceptance_criteria**:
  - GIVEN the 11 named schemas WHEN the file is inspected THEN none carries `x-openregister-authorization` or a bare `authorization` key
  - GIVEN a grep for `x-openregister-authorization` across the whole file WHEN run after Tasks 2–5 THEN it returns zero matches
- [x] Implement
- [x] Test

### Task 6: Verify import provisions all eight groups PLUS the `authenticated` side effect (observable state change)
- **spec_ref**: `openspec/changes/rbac-declare-groups/specs/rbac-groups/spec.md#requirement-req-005-declaring-the-authorization-blocks-and-scope-map-provisions-all-eight-groups-as-real-nextcloud-groups`
- **files**: none (verification against the running dev instance — `GET /cloud/groups` via OCS, no code change)
- **acceptance_criteria**:
  - GIVEN `GET /cloud/groups` matches zero of the eight canonical ids before import WHEN the updated register is imported THEN a subsequent `GET /cloud/groups` lists all eight, each with zero members, including `learners`/`coordinators`/`guardians`/`administration-managers` which no authorization rule in this change references — TC-1 in test-plan.md
  - GIVEN the register's content hash already matches (no-op skip case) AND an administrator has since deleted `learners` (declared scope-map-only, per REQ-004) WHEN the register is imported again THEN `learners` exists again — TC-2 in test-plan.md, proving the scope-map-only declaration path survives the import skip check, not just the authorization-block path
  - GIVEN the same post-import `GET /cloud/groups` response WHEN checked for a NINTH group literally named `authenticated` THEN it IS present, with zero members — TC-9 in test-plan.md; this is expected-but-undesirable (design.md Decision 7/C6), verified explicitly rather than assumed away, and should be raised with OpenRegister as a candidate third `RESERVED_PRINCIPALS` entry
- [ ] Implement
- [ ] Test

### Task 7: Verify the corrected rule on one actor — a `learners`-only user reads the catalogue AND is refused another learner's own record
- **spec_ref**: `openspec/changes/rbac-declare-groups/specs/rbac-groups/spec.md#requirement-req-002-21-named-tier-1-schemas-declare-narrow-individually-assigned-authorization`, `openspec/changes/rbac-declare-groups/specs/rbac-groups/spec.md#requirement-req-001-the-scholiq-register-declares-a-role-based-authorization-cascade-staff-only`, `openspec/changes/rbac-declare-groups/specs/rbac-groups/spec.md#requirement-req-003-21-named-tier-3-catalogue-schemas-declare-wide-read-staff-only-write-across-two-profiles`
- **files**: none (verification against the running dev instance)
- **acceptance_criteria**:
  - GIVEN a test user in no declared group WHEN they `GET` a `DossierNote` object THEN the response is 403, AND a test user in `instructors` gets 200 on the same object — TC-3/TC-4 in test-plan.md, proving the 403 is the group check working, not an unrelated failure
  - GIVEN a SINGLE test user in `learners` only WHEN they `GET` a `Course` object (catalogue) THEN the response is 200, AND WHEN the SAME user `GET`s or `POST`s a `GradeEntry` object owned by a DIFFERENT learner THEN both are refused (403) — TC-7 in test-plan.md, the paired check that catches the C5 bug: a suite that only asserted the refusal half would still pass on a broken (empty-catalogue) build
- [ ] Implement
- [ ] Test

## Verification
- [ ] All tasks checked off
- [ ] `openspec validate` passes
- [ ] Manual testing against acceptance criteria (Tasks 6–7 ARE the manual testing — no separate pass needed)
- [ ] Code review against spec requirements (REQ-001 through REQ-006, all six)

## Tests (company-wide ADR-009)
- N/A — no PHPUnit-testable business logic is added (config-only change, ADR-031). Coverage is TC-1 through TC-9 in test-plan.md, run against the live dev instance per Tasks 6–7.
- N/A — no new or changed API endpoints (this change consumes OpenRegister's existing RBAC/import endpoints unmodified).
- N/A — no UI changes.

## Documentation (company-wide ADR-010)
- N/A — no user-facing feature; the change is an access-control tightening on existing schemas, and its effect (four staff groups needing members; the residual per-learner-scoping gap) is the operational/product rollout note in proposal.md Risk 1 and Open Questions, not a `docs/` feature page.

## i18n (company-wide ADR-005)
- N/A — no new user-facing strings. Scope-map descriptions are internal OAS metadata, English per ADR-007/025 (source-of-truth), matching every existing string in `lib/Settings/scholiq_register.json`.

## Compliance
- `openspec validate --change rbac-declare-groups --strict` passes before this change is marked ready for apply.
- Diff is confined to `lib/Settings/scholiq_register.json` — no `lib/`, `src/`, or `appinfo/` PHP/Vue files change (ADR-031, and this change's own hard scope boundary).
