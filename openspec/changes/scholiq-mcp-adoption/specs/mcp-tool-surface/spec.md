# MCP Tool Surface

Learniq's agent-facing tool surface, derived by OpenRegister from a per-schema `x-openregister-mcp` declaration (ADR-063). Learniq writes no MCP tool code: it curates which schemas an agent may read, and OpenRegister derives `learniq.{schema}.{verb}` tools from that declaration.

## ADDED Requirements

### Requirement: Exactly five curated schemas declare the MCP dialect (REQ-001)
Learniq MUST declare `x-openregister-mcp` on exactly five schemas: `course`, `lesson`, `programme`, `assignment`, `regulation`, and MUST NOT declare it on any other schema in the Learniq register. `session` was on the July list and is now OFF: it gained `affectedLearnerIds` and `substituteTeacherId`, and the dialect cannot hide a property. The declaration MUST live at `components.schemas.<schema>.configuration["x-openregister-mcp"]` (the path `SchemaDerivedToolProvider` reads via `$schema->getConfiguration()`), and MUST carry `enabled: true`. Every other schema MUST have no `x-openregister-mcp` key at all, so that it derives no tool.

#### Scenario: The derived catalogue contains only the curated schemas
- GIVEN the Learniq register is imported
- WHEN OpenRegister's `SchemaDerivedToolProvider` builds the tool catalogue for app id `learniq`
- THEN it exposes tools for exactly the schemas `course`, `lesson`, `programme`, `assignment`, `regulation`
- AND no tool exists for `learner-profile`, `enrolment`, `grade-entry`, `attendance-record`, `submission`, `assessment`, `item`, `item-bank`, `cohort`, `material`, or any other schema
<!-- @e2e exclude MCP tool catalogue and register access rules, no UI; pinned by tests/Unit/Register/McpDialectRegisterTest.php. -->

#### Scenario: A schema without the dialect derives nothing
- GIVEN the `grade-entry` schema has no `configuration["x-openregister-mcp"]` key
- WHEN the derived catalogue is built
- THEN no `learniq.grade-entry.*` tool is registered
<!-- @e2e exclude MCP tool catalogue and register access rules, no UI; pinned by tests/Unit/Register/McpDialectRegisterTest.php. -->

### Requirement: The MCP surface is read-only — no write verb is declared (REQ-002)
Every declared verb MUST be `search` or `get`, MUST set `scope: "read"`, and MUST set `readOnlyHint: true`. Learniq MUST NOT declare `create`, `update`, or `delete` on any schema. Rationale (binding): `course.lifecycle`, `lesson.lifecycle`, and `assignment.lifecycle` are themselves the gate between draft and learner-visible content, so an `update` verb on any exposed schema is a publish verb; the dialect cannot express a lifecycle-scoped precondition, so no write verb may be declared until it can.

#### Scenario: No derived tool mutates a Learniq object
- GIVEN the derived catalogue for app id `learniq`
- WHEN every tool in it is inspected
- THEN each tool id ends in `.search` or `.get`
- AND no tool id ends in `.create`, `.update`, or `.delete`
- AND every tool declares `scope: "read"` and `readOnlyHint: true`
<!-- @e2e exclude MCP tool catalogue and register access rules, no UI; pinned by tests/Unit/Register/McpDialectRegisterTest.php. -->

### Requirement: No learner personal data and no exam content is exposed (REQ-003)
Learniq MUST NOT declare the MCP dialect on any schema carrying learner-identifiable data or exam content, at any verb — including `get`. This covers, without limitation: `learner-profile` (holds `bsnEncrypted`, `birthDate`, `parentIds`), `enrolment`, `submission`, `grade-entry`, `final-grade`, `assessment-result`, `competency-attainment`, `attendance-record`, `attendance-flag`, `lesson-completion`, `xapi-statement`, `engagement-score`, `engagement-risk-flag`, `bsa-trajectory`, `bsa-progress-flag`, `bsa-warning`, `bsa-decision`, `fraud-case`, `exemption-case`, `deliberation-record`, `attestation`, `credential`, `external-training-record`, `learning-plan`, `learning-plan-evaluation`, `signature`, `support-request`, `tlv-application`, `grade-notification`, `conference-signup`, `conference-slot`, `conference-report`, `praktijkopleider`, `bpv-placement`, `praktijkovereenkomst`, `pok-signature`, `werkproces-assessment`, `bpv-visit-report`, `teacher-availability`, `session` (holds `affectedLearnerIds` and `substituteTeacherId`), and `cohort` (holds `learnerIds`/`teacherIds` — a class roster). It covers the AVG art. 9 special-category schemas `excuse-request` (absence reasons are health data about a minor) and `proctoring-session` (behavioural exam-surveillance artefacts). It covers the exam-integrity schemas `item` (holds `correctResponse`), `item-bank`, and `assessment` (holds `itemRefs`, `passMark`). It covers `material`, which has no `lifecycle` property and therefore cannot carry the conditional-read rule of REQ-005, while being reachable from `assignment.briefingMaterialIds` — exposing it would route around the `assignment` gate.

#### Scenario: A learner-data schema derives no tool even for a single-object fetch
- GIVEN a caller asks the agent for one pupil's grade by object id
- WHEN the agent searches its tool catalogue
- THEN no `learniq.grade-entry.get` tool exists to call
- AND no `learniq.learner-profile.get` tool exists to call
<!-- @e2e exclude MCP tool catalogue and register access rules, no UI; pinned by tests/Unit/Register/McpDialectRegisterTest.php. -->

#### Scenario: Exam item banks are unreachable from the agent surface
- GIVEN a student-facing agent session
- WHEN the tool catalogue is enumerated
- THEN no `learniq.item.*`, `learniq.item-bank.*`, or `learniq.assessment.*` tool is present
<!-- @e2e exclude MCP tool catalogue and register access rules, no UI; pinned by tests/Unit/Register/McpDialectRegisterTest.php. -->

### Requirement: Every declared search filter is a real property of its schema (REQ-004)
Every entry in a `search.filters` list MUST be the name of a property that exists in that schema's `properties` block. OpenRegister's `McpAnnotationValidator::validateFilters()` rejects an unknown filter with `mcp-unknown-filter-property` and fails the register import, so an incorrect list is a hard import failure, not a silent degradation. The declared filters MUST be: `course` → `code`, `level`, `language`, `lifecycle`, `mandatoryTraining`, `regulationSlug`; `lesson` → `courseId`, `contentType`, `lifecycle`, `mandatoryTraining`; `programme` → `code`, `level`, `lifecycle`; `assignment` → `courseId`, `sessionId`, `cohortId`, `lifecycle`; `regulation` → `slug`, `active`, `audienceScope`, `requiresAnnualRenewal`, `lifecycle`.

#### Scenario: The register imports without a dialect validation error
- GIVEN the five curated schemas declare their `search.filters`
- WHEN the Learniq register is imported into OpenRegister
- THEN `McpAnnotationValidator::validate()` returns no `mcp-unknown-filter-property` error
- AND no `mcp-unknown-verb`, `mcp-bad-scope`, `mcp-bad-hint`, or `mcp-missing-enabled` error is returned
<!-- @e2e exclude MCP tool catalogue and register access rules, no UI; pinned by tests/Unit/Register/McpDialectRegisterTest.php. -->

### Requirement: Draft and archived content is not readable by non-admin callers (REQ-005)
Each exposed schema MUST carry an `authorization.read` list that gives the staff groups allowed to write it unconditional read and restricts every other signed-in user to the schema's live lifecycle values: `course`, `lesson`, `programme` → staff `instructors`, `hr`, `compliance-officers`, `team-leads`, plus `{"group": "authenticated", "match": {"lifecycle": {"$eq": "published"}}}`; `assignment` → the same staff, plus `{"lifecycle": {"$in": ["published", "closed"]}}`; `regulation` → staff `compliance-officers`, `team-leads`, plus `{"lifecycle": {"$eq": "published"}}`. Nextcloud admins keep full read through OpenRegister's admin bypass; `admin` is not a declared group and MUST NOT be listed. This requirement replaces, and MUST land in the same change as, the hand-written non-admin lifecycle gate in `LearniqToolProvider`; the rule is stricter than the old one because it applies to the REST API and the UI as well.

#### Scenario: A non-admin search returns no draft courses
- GIVEN a draft course and a published course exist
- AND the caller is a signed-in user in none of the staff groups
- WHEN `learniq.course.search` is invoked with no filters
- THEN only the published course is returned
- AND the draft course is absent from the result set
<!-- @e2e exclude MCP tool catalogue and register access rules, no UI; pinned by tests/Unit/Register/McpDialectRegisterTest.php. -->

#### Scenario: A non-admin get on a draft course is denied
- GIVEN a course with `lifecycle: draft`
- AND the caller is a signed-in user in none of the staff groups
- WHEN `learniq.course.get` is invoked with that course's id
- THEN OpenRegister's RBAC read check denies the object
- AND the draft course's existence is not disclosed to the caller
<!-- @e2e exclude MCP tool catalogue and register access rules, no UI; pinned by tests/Unit/Register/McpDialectRegisterTest.php. -->

#### Scenario: An admin still sees drafts
- GIVEN a course with `lifecycle: draft`
- AND the caller is a Nextcloud admin
- WHEN `learniq.course.search` is invoked
- THEN the draft course is returned
<!-- @e2e exclude MCP tool catalogue and register access rules, no UI; pinned by tests/Unit/Register/McpDialectRegisterTest.php. -->

### Requirement: No hand-written MCP tool code remains in Learniq (REQ-006)
Learniq MUST NOT ship any `IMcpToolProvider` implementation, and MUST NOT register an `mcpProvider` alias. `lib/Mcp/LearniqToolProvider.php`, `tests/Unit/Mcp/LearniqToolProviderTest.php`, the `'mcpProvider' => LearniqToolProvider::class` option in `lib/AppInfo/Application.php`, and the now-dead `tests/Stubs/Mcp/IMcpToolProvider.php` stub MUST be deleted. Because a hand-written tool takes precedence over a derived tool, a surviving `learniq.listCourses` would permanently shadow `learniq.course.search` and render the entire dialect inert. No `#[McpTool]` attribute and no `IMcpScannableServices` implementation are needed, because both hand-written tools are derivable CRUD and no curated tool survives the migration.

#### Scenario: The derived tools are not shadowed
- GIVEN the Learniq register declares the dialect
- WHEN the MCP tool catalogue for app id `learniq` is enumerated
- THEN `learniq.course.search` and `learniq.course.get` are present
- AND `learniq.listCourses` and `learniq.getCourseDetails` are absent
<!-- @e2e exclude MCP tool catalogue and register access rules, no UI; pinned by tests/Unit/Register/McpDialectRegisterTest.php. -->

#### Scenario: The app registers no tool provider
- GIVEN Learniq is installed and enabled
- WHEN the container is asked for `OCA\OpenRegister\Mcp\IMcpToolProvider::learniq`
- THEN no service is registered under that alias
<!-- @e2e exclude MCP tool catalogue and register access rules, no UI; pinned by tests/Unit/Register/McpDialectRegisterTest.php. -->
