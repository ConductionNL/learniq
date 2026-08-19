# RBAC Groups Specification

**Status**: in-progress
**Scope**: scholiq
**OpenSpec changes**:
- `rbac-declare-groups` (in-progress) — declares the register-level authorization cascade, the 42 named schema-level authorization blocks, and the OAuth2 scope map so OpenRegister's `GroupProvisioner` creates the canonical eight groups (`instructors`, `hr`, `compliance-officers`, `team-leads`, `learners`, `coordinators`, `guardians`, `administration-managers`) as real Nextcloud groups. Group ids are sector-neutral (Scholiq is being reframed to serve both schools and companies) — see design.md Decision 2/C3 for the education/corporate label mapping. The load-bearing distinction across every requirement below is **catalogue vs. learner-attributed data**: a learner reads the course catalogue (what's on offer) at register-cascade-exceeding breadth, but never another learner's own record (what a specific person did) — see REQ-001/REQ-003 and design.md's Residual Exposure section.

## Purpose
Defines the authorization data model scholiq's `lib/Settings/scholiq_register.json` MUST declare so that OpenRegister's existing RBAC engine (`PermissionHandler`, `MagicRbacHandler`, `OasService`, `GroupProvisioner` — see `openregister/openspec/specs/rbac-scopes/spec.md`) actually gates access to the 118 schemas instead of defaulting every one of them open. This app declares data; OpenRegister enforces it (ADR-023 Rule 1 — apps never roll their own data RBAC). Nothing in this spec introduces new PHP — every requirement below is satisfied by JSON content in the schema register (ADR-031).

## ADDED Requirements

### Requirement: REQ-001: The scholiq register declares a role-based authorization cascade, staff-only
The `scholiq` register's `configuration.roles` array SHALL declare a single `read-write` role (`actions: ["read","create","update"]`). The register's own `authorization` block SHALL assign `read-write` to `["instructors","hr","compliance-officers","team-leads"]` and SHALL NOT assign any role, nor any bare action, to `learners`. This cascade SHALL be the effective authorization for every schema that does not declare its own `authorization` block — Tier 2, the **learner-attributed records** population (what a specific person did: e.g. `GradeEntry`, `FinalGrade`, `AssessmentResult`, `ReportCard`, `GradeNotification`, `Enrolment`, `LessonCompletion`, `CompetencyAttainment`, `EngagementScore`, `LearnerEngagement`, `PointAward`, `PortfolioEntry` — 76 schemas after REQ-003's catalogue schemas are carved out into their own tier below). `learners` receiving no blanket grant on THIS population is deliberate, not an oversight — see design.md's "Residual Exposure" section for why a group-level grant is the wrong instrument for learner-attributed data and what closes the resulting gap. This is distinct from, and MUST NOT be confused with, REQ-003's catalogue/definitional population, where `learners` DOES get a wide read grant — see REQ-003.

#### Scenario: A Tier-2 (learner-attributed) schema with no block inherits the register cascade
- **GIVEN** schema `Enrolment` (Tier 2 — learner-attributed) has no `authorization` block of its own
- **AND** the register declares the cascade above
- **WHEN** a user in group `instructors` creates an `Enrolment` object
- **THEN** the create succeeds (via the `read-write` role)
- **AND** `Course` is NOT an example of this — `Course` is Tier 3 (catalogue) after REQ-003, not Tier 2; see REQ-003 for why it needs a schema-level block instead of relying on this cascade

#### Scenario: A learner is refused BOTH read and write on a Tier-2 schema under the register cascade
- **GIVEN** schema `GradeEntry` (Tier 2) has no `authorization` block of its own
- **AND** a user is a member of `learners` only and no other declared group
- **WHEN** that user attempts to `read` or `create` a `GradeEntry` object that a different learner owns
- **THEN** both actions are refused — the register block names no role or action for `learners`, and the block being non-empty means an unlisted group is denied rather than falling through to open-by-default
- **AND** this is the deliberate fix for the lateral-disclosure risk a blanket `learners: read` grant would have created (any learner reading any other learner's grades) — see design.md's Residual Exposure section for how a learner is meant to reach their OWN `GradeEntry` instead

#### Scenario: Delete is admin-only by omission, not by an explicit rule
- **GIVEN** the register's `authorization` block declares only the `read-write` role assignment, with no `delete` key
- **WHEN** a user who is not in the `admin` group and is not the object owner attempts to delete a Tier-2 object
- **THEN** the delete is refused, because `PermissionHandler::hasGroupPermission()` denies any action not explicitly listed once a schema's `authorization` block is non-empty

### Requirement: REQ-002: 21 named Tier-1 schemas declare narrow, individually-assigned authorization
The following 21 schemas hold sensitive personal data about minors or formal compliance/assessment decisions and SHALL each declare its own schema-level `authorization` block — none may rely on the register cascade. Each is assigned to exactly one of four profiles:

- **Profile A — Care & compliance record** (`read`/`create`/`update`: `instructors`, `compliance-officers`): `DossierNote`, `WellbeingCheckIn`, `BehaviourIncident`, `ExcuseRequest`, `AttendanceRecord`, `AttendanceFlag`, `ExemptionCase`, `ProctoringSession`, `FraudCase`
- **Profile B — Formal decision / assessment record** (`read`/`create`/`update`: `compliance-officers`, `team-leads`): `TlvApplication`, `BsaDecision`, `BsaWarning`, `BsaTrajectory`, `ConferenceReport`, `PeerReview`, `SelfAssessment`, `Submission`
- **Profile C — Credential / training record** (`read`/`create`/`update`: `hr`, `compliance-officers`): `Attestation`, `ExternalTrainingRecord`, `Credential`
- **Profile D — Learner profile** (`read`: `instructors`, `hr`, `compliance-officers`; `create`/`update`: `hr`, `compliance-officers`): `LearnerProfile`

No profile lists `delete` — by REQ-001's fail-closed rule, delete is admin-only on every Tier-1 schema by omission. `learners` is not a member of any Tier-1 profile: a learner reads their own record, where applicable, only via the pre-existing object-owner bypass (`PermissionHandler` grants the object's owner full access regardless of group), never via a group grant.

#### Scenario: A non-member is refused READ on a Tier-1 schema (the exposure this change closes)
- **GIVEN** schema `DossierNote` (Profile A) declares `authorization.read: ["instructors","compliance-officers"]`
- **AND** an authenticated user who is in neither `instructors` nor `compliance-officers`, and is not the note's `authorId`
- **WHEN** that user sends `GET` for a `DossierNote` object
- **THEN** the read is refused with **404** — 404, not 403 — OpenRegister's `ObjectsController` deliberately remaps a read denial to Not Found so the denial reveals nothing about whether the object exists ("Mirror show(): 404, not 403/500, so denial reveals nothing"). Writes still surface as a true 403. Verified live 2026-08-19.
- **AND** this is the behaviour that did NOT hold before this change: with no `authorization` block, `PermissionHandler::hasGroupPermission()`'s "Default-OPEN behaviour preserved" branch would have returned `true` for this same read, for this same user, unconditionally — `enforce_default_closed` would not have changed this outcome even if enabled, because `read` is never in `DEFAULT_CLOSED_WRITE_ACTIONS`

#### Scenario: A member of the assigned profile is admitted
- **GIVEN** schema `BsaDecision` (Profile B) declares `authorization.read: ["compliance-officers","team-leads"]`
- **AND** a user is a member of `compliance-officers`
- **WHEN** that user reads a `BsaDecision` object
- **THEN** the read succeeds

#### Scenario: A user in a Tier-1 schema's non-assigned staff group is still refused
- **GIVEN** schema `Credential` (Profile C) declares `authorization.read: ["hr","compliance-officers"]`
- **AND** a user is a member of `instructors` only (a real staff group, just not one this profile names)
- **WHEN** that user reads a `Credential` object
- **THEN** the read is refused — membership in *a* declared group is not membership in *the* declared group for this schema

### Requirement: REQ-003: 21 named Tier-3 catalogue schemas declare wide read, staff-only write across two profiles
Tier 3 is the **catalogue/definitional** population: what is on offer, not what any specific person did. Nothing in this tier is personal data about anyone. Every schema in it SHALL declare a schema-level `authorization` block with `read: ["authenticated"]` — every authenticated user, `learners` included. This is **deliberately wider than the Tier-2 register cascade** (REQ-001), which grants no read to `learners` at all; the two populations are different on purpose (REQ-001 covers learner-ATTRIBUTED data, this covers catalogue data) and this requirement's `read: ["authenticated"]` MUST NOT be read as "matching" or "inheriting" REQ-001's breadth — it is wider than it, stated explicitly because a schema-level block, once present, is exclusive and never merges with the cascade (most-specific-wins).

Write is staff-only, split into two profiles because "staff-only" means different staff depending on who authors the content:

- **Profile 3a — Shared configuration/catalogue** (`create`/`update`: `compliance-officers`, `team-leads` — narrower than the Tier-2 cascade's four-role write grant, because these are shared configuration objects that ordinary `instructors`/`hr` staff should not casually edit): `GradeScale`, `ReportPeriod`, `CompetencyFramework`, `PointRule`, `EngagementLevel`, `Leaderboard`, `CourseTemplate`, `PortfolioTemplate`, `LearningPlanTemplate`, `Regulation`, `ExchangeErrorCode`, `Room`, `FeeItem` (13 schemas)
- **Profile 3b — Course-content catalogue** (`create`/`update`: `instructors`, `hr`, `compliance-officers`, `team-leads` — the SAME four-role write grant as the Tier-2 cascade, because instructors are the actual authors of this content and excluding them would break authoring, not just reading): `Course`, `Lesson`, `Material`, `Programme`, `CurriculumPlan`, `Assignment`, `Assessment`, `ItemBank` (8 schemas)

Profile 3b exists because the object-owner bypass does not make Tier-2's staff-only cascade safe for these 8 schemas: an instructor AUTHORS a `Course`, so `objectOwner` is the instructor, and a learner opening the course catalogue is never the object's owner — under the Tier-2 cascade alone, every learner-facing catalogue view would render empty. A schema-level block here is not decoration; it is the only way to grant `learners` a read that REQ-001 deliberately withholds elsewhere.

**Approximation acknowledged**: `Assignment`, `Assessment`, and `ItemBank` are treated as wholly catalogue at the schema level. If any of these schemas' properties later carry result-bearing data (a score embedded in `Assessment` rather than kept in the separate `AssessmentResult` schema), that data would need property-level `authorization`, not a schema-level widening — out of scope for this change's effort budget, flagged here so a future schema-property audit knows to check.

#### Scenario: A learner reads the catalogue and is refused another learner's own record — both halves on the same actor
- **GIVEN** a user is a member of `learners` only
- **AND** schema `Course` (Tier 3, Profile 3b) declares `authorization.read: ["authenticated"]`
- **AND** schema `GradeEntry` (Tier 2, no schema-level block) exists with an object owned by a DIFFERENT learner
- **WHEN** that user `GET`s a `Course` object, and separately `GET`s the `GradeEntry` object
- **THEN** the `Course` read succeeds (catalogue: wide read, learners included)
- **AND** the `GradeEntry` read is refused with **404** and its create with **403** (learner-attributed: no group grant for `learners`, per REQ-001). 404, not 403 — OpenRegister's `ObjectsController` deliberately remaps a read denial to Not Found so the denial reveals nothing about whether the object exists ("Mirror show(): 404, not 403/500, so denial reveals nothing"). Writes still surface as a true 403. Verified live 2026-08-19.
- **AND** this is the corrected rule this change actually implements: not "learners read nothing," but "learners read the catalogue, never another learner's record"

#### Scenario: Write is staff-only in both profiles, but which staff differs
- **GIVEN** schema `GradeScale` (Profile 3a) declares `authorization.update: ["compliance-officers","team-leads"]`
- **AND** schema `Lesson` (Profile 3b) declares `authorization.update: ["instructors","hr","compliance-officers","team-leads"]`
- **AND** a user is a member of `instructors` only
- **WHEN** that user attempts to update a `GradeScale` object, and separately a `Lesson` object
- **THEN** the `GradeScale` update is refused (Profile 3a deliberately excludes `instructors`)
- **AND** the `Lesson` update succeeds (Profile 3b includes `instructors` — they author lesson content)
- **AND** both blocks override the register cascade entirely for their own schema (most-specific-wins — never merged with it)

### Requirement: REQ-004: The register's OAuth2 scope map declares the canonical eight group ids
`lib/Settings/scholiq_register.json`'s `components.securitySchemes.oauth2.flows.authorizationCode.scopes` map SHALL list all eight canonical group ids (`instructors`, `hr`, `compliance-officers`, `team-leads`, `learners`, `coordinators`, `guardians`, `administration-managers`), each with a human-readable description, independent of whether every group appears in every schema's authorization block. Only four of the eight (`instructors`, `hr`, `compliance-officers`, `team-leads`) are referenced by an authorization block this change writes. The other four — `learners` (deliberately excluded from every authorization block per REQ-001's staff-only cascade — see design.md's Residual Exposure section), and `coordinators`/`guardians`/`administration-managers` (not yet assigned any permission, reserved for the sibling `fix-dead-role-gates` change) — are declared SOLELY via the scope map. Declaring a group via the scope map alone, with no authorization-block reference, is a documented, independent path supported by `RbacGroupCollector::fromScopeMap()`.

#### Scenario: The scope map alone yields every group this register depends on
- **GIVEN** the completed register file
- **WHEN** the scope map is read in isolation, without walking any `authorization` block
- **THEN** all eight group ids are present
- **AND** `admin` and `public` are absent (reserved principals are never provisioned — `RbacGroupCollector::RESERVED_PRINCIPALS`)

#### Scenario: A group declared only in the scope map, referenced by no authorization block, is still provisioned
- **GIVEN** `learners` appears in the scope map but in no register, schema, or property `authorization` block anywhere in the file (REQ-001 deliberately assigns it no role)
- **WHEN** `RbacGroupCollector::fromDocument()` collects group ids
- **THEN** `learners` is included in the result (via `fromScopeMap()`, unioned with the authorization-derived set)
- **AND** the next import provisions `learners` as a real Nextcloud group, exactly as it would if an authorization block referenced it — the group exists and is ready for object-level scoping to reference later (see design.md's Residual Exposure section), even though no CURRENT authorization block grants it anything

### Requirement: REQ-005: Declaring the authorization blocks and scope map provisions all eight groups as real Nextcloud groups
Once the register declares the blocks in REQ-001 through REQ-004, the next configuration import SHALL cause OpenRegister's `GroupProvisioner` to create every declared group id that does not already exist, as a real Nextcloud group, visible via `OCP\IGroupManager` and the `/cloud/groups` OCS endpoint. Provisioning is create-only: the created groups start with zero members and therefore deny every caller until an administrator populates them — that is OpenRegister's documented contract, not a defect of this change. This holds equally for the four groups this change's authorization blocks reference (`instructors`, `hr`, `compliance-officers`, `team-leads`) and the four declared only via the scope map (`learners`, `coordinators`, `guardians`, `administration-managers`).

#### Scenario: An import that previously created nothing now creates all eight groups
- **GIVEN** none of the eight canonical group ids exists as a Nextcloud group before import (the measured pre-change state: `GET /cloud/groups` matched zero `/scholiq/i`-adjacent or role-named groups for this app)
- **WHEN** the updated `scholiq_register.json` is imported
- **THEN** a subsequent `GET /cloud/groups` lists all eight group ids
- **AND** each of the eight has zero members immediately after import

#### Scenario: A group deleted by an administrator is restored on the next import, even if content is otherwise unchanged
- **GIVEN** the register's stored content hash already matches the file (a re-import would otherwise be a no-op)
- **AND** an administrator has since deleted the `learners` Nextcloud group by hand
- **WHEN** the register is imported again
- **THEN** `learners` exists again as a Nextcloud group (OpenRegister provisions declared groups before the import skip check, per `openregister/openspec/specs/rbac-scopes/spec.md`'s "Declared groups MUST be provisioned before the import skip check" requirement)

#### Scenario: A ninth group, `authenticated`, is ALSO provisioned — a known, expected-but-undesirable side effect
- **GIVEN** REQ-003's 21 Tier-3 schemas each declare `authorization.read: ["authenticated"]` as a literal string
- **AND** `RbacGroupCollector::RESERVED_PRINCIPALS` is `['admin', 'public']` only — `authenticated` is NOT reserved, even though `PermissionHandler` (~line 750, `"'authenticated' pseudo-group: any logged-in user qualifies, independent of real group membership"`) and `MagicRbacHandler` both treat it as a pseudo-group exactly like `public`, never as a real, membership-tested group
- **WHEN** the register is imported
- **THEN** `GET /cloud/groups` lists a NINTH group, literally named `authenticated`, alongside the eight canonical ids
- **AND** that group has zero members and will always have zero members with any operational meaning — `PermissionHandler`/`MagicRbacHandler` never test membership in it, they test `$userId !== null`
- **AND** this is expected under the current `RbacGroupCollector` implementation, not a defect in this change's authorization content — see design.md's "`authenticated` wart" note for why it is flagged rather than silently tolerated

### Requirement: REQ-006: The `x-openregister-authorization` decoy key is removed from every schema that carries it
Twenty schemas carry an `x-openregister-authorization` key that no OpenRegister code path reads (`Schema`, `PermissionHandler`, `OasService`, and `RbacGroupCollector` all read the bare `authorization` key only). This change SHALL remove that key from all twenty: replaced by a real `authorization` block on the 9 that overlap with REQ-002/REQ-003's named schemas (`DossierNote`, `BehaviourIncident`, `ProctoringSession`, `TlvApplication`, `BsaDecision`, `BsaWarning`, `PeerReview`, `ReportPeriod`, `Room` — the Tier-1/Tier-3 overlap), and deleted outright on the remaining 11 (`SovereigntyPolicy`, `XapiStatement`, `Application`, `ExamAccommodation`, `ReportCard`, `SupportRequest`, `DeliberationRecord`, `EngagementRiskThreshold`, `EngagementRiskFlag`, `AccessibilityStatement`, `AccessibilityLimitation`), which fall back cleanly to the Tier-2 register cascade with no schema-level key at all.

#### Scenario: A decoy-only schema has no leftover authorization-shaped key after this change
- **GIVEN** schema `SupportRequest` carried `x-openregister-authorization` before this change and is not named in REQ-002 or REQ-003
- **WHEN** the register file is inspected after this change
- **THEN** `SupportRequest` has neither an `x-openregister-authorization` key nor a bare `authorization` key
- **AND** its effective authorization is the register cascade from REQ-001

#### Scenario: A schema that gets a real block has the decoy key removed, not merely superseded
- **GIVEN** schema `DossierNote` carried both content that looked like a working `create` rule under `x-openregister-authorization` and this change's real `authorization` block (Profile A)
- **WHEN** the register file is inspected after this change
- **THEN** `DossierNote` has no `x-openregister-authorization` key
- **AND** a reader inspecting the schema sees exactly one authorization-shaped key, and it is the one OpenRegister enforces

## Non-Functional Requirements

- **Performance:** No new database queries or services are introduced; RBAC evaluation cost is OpenRegister's existing per-request cost (already paid on every schema, declared or not).
- **Accessibility:** N/A — this is a data/configuration change with no UI surface.
- **Internationalization:** Scope-map descriptions and role descriptions are written in English per ADR-007/025 (source-of-truth), matching every other string in `lib/Settings/scholiq_register.json`.

## Acceptance Criteria

- Every one of the 118 schemas has an effective `authorization` posture after this change: 42 via their own block (21 Tier-1 + 21 Tier-3, the latter split 13/8 across Profiles 3a/3b), 76 via the register cascade — none remain on OpenRegister's undeclared/open-by-default path.
- A `learners`-only user can read a Tier-3 catalogue object (e.g. `Course`) AND is refused read on a learner-attributed Tier-2 object owned by a different learner (e.g. `GradeEntry`) — proven on the SAME actor, not two separate claims (see REQ-003's paired scenario). This is the corrected rule this change implements: learners read the catalogue, never another learner's record.
- `GET /cloud/groups` on the target instance lists all eight canonical group ids (`instructors`, `hr`, `compliance-officers`, `team-leads`, `learners`, `coordinators`, `guardians`, `administration-managers`) after the next configuration import.
- A user with no declared-group membership is refused `read` on at least one Tier-1 schema (proven, not merely declared — see REQ-002's first scenario).
- A `learners`-only user is refused both `read` and `create` on a Tier-2 schema under the register cascade (proven, not merely declared — see REQ-001's second scenario). No blanket group grant admits a learner to another learner's records.
- No PHP file changes; the diff is confined to `lib/Settings/scholiq_register.json`.

## Notes
- `PermissionHandler`'s partial `enforce_default_closed` operator flag (`IAppConfig` key `openregister.enforce_default_closed`, default `false`, measured `UNSET` on localhost:8080) only affects `create`/`update`/`delete` on undeclared schemas — it was never able to close `read`, which is the exposure this change actually closes. This spec does not depend on that flag and does not recommend enabling it as a substitute for declaring blocks.
- 20 schemas currently carry an `x-openregister-authorization` key that OpenRegister never reads (`Schema`, `PermissionHandler`, `OasService`, `RbacGroupCollector` all read the bare `authorization` key only). This change removes that decoy key everywhere it appears — replaced by a real `authorization` block on the 9 schemas that overlap with Tier 1/Tier 3, deleted outright on the other 11 (which fall back to the Tier-2 register cascade).
- `DashboardRoleService`'s `scholiq-{role}` group-membership tests (compliance-officer/hr/manager/instructor, prefixed and singular) do not match the group ids this spec declares. Reconciling `DashboardRoleService` to read the eight canonical, unprefixed group ids (`instructor`/`team-lead`/`coordinator`/`hr`/`compliance-officer`/`guardian`/`administration-manager`/`learner` as resolved roles, mapping to the `instructors`/`team-leads`/`coordinators`/`hr`/`compliance-officers`/`guardians`/`administration-managers`/`learners` groups this spec declares) is a PHP change, out of scope here — it is the `fix-dead-role-gates` change, whose vocabulary this spec's group ids were coordinated against so the two changes converge on the same eight ids rather than re-creating the original mismatch under new names.
- Group ids are sector-neutral by design (`instructors`/`compliance-officers`/etc., not `teachers`/`principals`) because Scholiq is being reframed to serve both schools and companies (Learniq); "school" words (Teacher, Mentor, Principal, Parent) become presentation-layer LABELS for the same underlying group id, not the id itself. See design.md Decision 2 for the full label-mapping table.
- **`authenticated` will be provisioned as a real, empty Nextcloud group** — a known wart of the current `RbacGroupCollector` implementation, not a defect in this spec's authorization content. `RbacGroupCollector::RESERVED_PRINCIPALS` is `['admin', 'public']` only; `authenticated` isn't reserved even though `PermissionHandler`/`MagicRbacHandler` both treat it purely as a pseudo-group (membership is never tested). REQ-003's 21 catalogue schemas all use the literal string `read: ["authenticated"]`, so the next import provisions a ninth, permanently-empty, permanently-meaningless group named `authenticated` alongside the eight canonical ids. Task 6/TC-9 verifies this happens as expected rather than assuming it away; it should be raised with OpenRegister as a candidate third reserved principal, not silently tolerated on every app that uses the pseudo-group in an authorization rule.
- Related: `openregister/openspec/specs/rbac-scopes/spec.md` (the enforcement engine and group-provisioning mechanism this spec's requirements assume); ADR-023 (`hydra/openspec/architecture/adr-023-action-authorization.md`, data-vs-action RBAC split — this spec is entirely data RBAC, Rule 1).
