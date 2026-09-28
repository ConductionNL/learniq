# MCP Tool Surface: hermiq-ai-tooling delta

Extends the capability created by `scholiq-mcp-adoption` with governed write tools and one curated, field-minimised read. REQ-001 to REQ-006 are claimed by `scholiq-mcp-adoption`; this delta modifies REQ-006 and adds REQ-007 to REQ-011. The derived read surface (REQ-001 to REQ-005) is untouched.

## MODIFIED Requirements

### Requirement: No hand-written MCP tool code remains in Scholiq (REQ-006)
Learniq MUST NOT ship any `IMcpToolProvider` implementation and MUST NOT register an `mcpProvider` alias, because a hand-written provider tool takes precedence over a derived tool and would shadow the dialect surface. Learniq MAY ship curated `#[McpTool]` methods on services listed by an `IMcpScannableServices` implementation registered under `OCA\OpenRegister\Mcp\IMcpScannableServices::learniq`, and MUST do so only for what the derived surface cannot safely provide: governed write actions (REQ-007 to REQ-010) and field-minimised reads (REQ-011). Every curated tool id MUST be 2-segment (`learniq.{toolName}`) so it can never collide with a derived `learniq.{schema}.{verb}` id, and every curated tool MUST declare `scope`, `subject`, `action` and the three hints.

#### Scenario: The derived tools are not shadowed
- GIVEN the learniq register declares the dialect
- WHEN the MCP tool catalogue for app id `learniq` is enumerated
- THEN `learniq.course.search` and `learniq.course.get` are present
- AND `learniq.listCourses` and `learniq.getCourseDetails` are absent
<!-- @e2e exclude Backend catalogue enumeration, no UI; pinned by tests/Unit/Register/McpDialectRegisterTest.php. -->

#### Scenario: The app registers no tool provider but does register scannable services
- GIVEN learniq is installed and enabled
- WHEN the container is asked for `OCA\OpenRegister\Mcp\IMcpToolProvider::learniq`
- THEN no service is registered under that alias
- AND `OCA\OpenRegister\Mcp\IMcpScannableServices::learniq` resolves to `LearniqScannableServices` returning `[LearniqAgentTools::class]`
<!-- @e2e exclude DI registration shape, no UI; pinned by tests/Unit/Service/LearniqAgentToolsTest.php. -->

## ADDED Requirements

### Requirement: Write actions are curated tools with declared scope (REQ-007)
Learniq MUST expose exactly three write tools as `#[McpTool]` methods on `LearniqAgentTools`: `learniq.enrolLearner` (subject `enrolment`, action `create`), `learniq.recordAttendance` (subject `attendance`, action `create`) and `learniq.gradeSubmission` (subject `grade`, action `propose`), each with scope `create` and `readOnlyHint: false`. No tool in this change MAY declare scope `update` or `delete`; revoking, un-enrolling and overwriting a grade need their own change. Issuing a credential is not a tool: a credential is signed and leaves the instance the moment it exists, the schema has no draft state a teacher could accept, and decision rule "every AI-assisted action is a draft the teacher accepts" (LANE-RULES-R2) therefore rules it out until the schema gains one.

#### Scenario: The catalogue carries exactly three write tools with honest metadata
- GIVEN the curated tools on `LearniqAgentTools`
- WHEN every tool with a non-read scope is inspected
- THEN exactly `enrolLearner`, `recordAttendance` and `gradeSubmission` are found
- AND each declares scope `create`, `readOnlyHint: false`, a subject and an action
<!-- @e2e exclude Backend catalogue metadata, no UI; pinned by tests/Unit/Service/LearniqAgentToolsTest.php. -->

### Requirement: Every write tool delegates to the existing guarded path and cannot bypass a gate (REQ-008)
Each write tool MUST call `ActionAuthService::requireAction()` with its own action id (`mcp.enrol-learner`, `mcp.record-attendance`, `mcp.grade-submission`, seeded admin-only in `lib/actions.seed.json` per ADR-023) before any read or write, validate its arguments, read every referenced object with the caller's own rights, and write through `ObjectService::saveObject()` in the caller's session with RBAC on: the path the app's own screens use, so every lifecycle guard and register access rule runs unchanged. A write that a guard or an access rule refuses MUST reach the agent as that refusal and MUST store nothing.

#### Scenario: A guard rejection reaches the agent unchanged
- GIVEN a guard refuses the grade (for example, the report period is locked)
- WHEN `learniq.gradeSubmission` is invoked for it
- THEN the tool answers `{ok: false, error: {code: "refused", message: <the guard's message>}}`
- AND no grade-entry object is created
<!-- @e2e exclude Backend gate parity, no UI; pinned by tests/Unit/Service/LearniqAgentToolsTest.php. -->

#### Scenario: The ADR-023 matrix gates the tool before any domain logic
- GIVEN a caller whose groups are not granted the tool's action in the matrix
- WHEN any write tool is invoked
- THEN the tool answers with a refusal
- AND no object is read or written
<!-- @e2e exclude Backend authorization, no UI; pinned by tests/Unit/Service/LearniqAgentToolsTest.php. -->

### Requirement: An agent grade is a concept a teacher publishes (REQ-009)
`learniq.gradeSubmission` MUST write the grade as a `GradeEntry` in lifecycle `concept`, with `sourceKind: assignment-submission`, exactly as the marking screen does; it MUST NOT publish, revise or invalidate a grade. Only a teacher publishing the concept in the gradebook (the `publish` transition, guarded by `ReportPeriodLockGuard`) makes the grade visible or counts it. Human approval of the tool call itself is hermiq's confirm gate (hermiq agent-guardrails: a confirm-classified tool call waits for an approved, unconsumed Approval); learniq does not stage proposals or verify approval tokens of its own. `enrolLearner` writes a `pending` enrolment and `recordAttendance` writes or corrects one record; both are reversible and not certifying.

#### Scenario: An agent-proposed grade stays invisible until a teacher publishes it
- GIVEN an agent proposes a grade for a handed-in submission
- WHEN the tool completes
- THEN a `GradeEntry` in lifecycle `concept` exists for the learner
- AND the learner does not see it until a teacher publishes it
<!-- @e2e exclude Backend write shape, no UI in learniq for agent calls; pinned by tests/Unit/Service/LearniqAgentToolsTest.php. -->

### Requirement: Every agent write says it was made by a tool (REQ-010)
Every write through a curated tool MUST carry, on the record itself, the calling user (`markedBy`, `grader`) and a note naming the tool (`learniq.<tool>`) in the record's reason or comment field. OpenRegister's attribute tool provider records one immutable audit entry per invocation with the tool id and the calling user (REQ-ATTR-004), and hermiq's run log names the agent; learniq does not see the agent identity and does not invent one.

#### Scenario: A grade proposed by an agent is answerable as such
- GIVEN a grade written through `learniq.gradeSubmission`
- WHEN a teacher reads the grade
- THEN its `grader` is the calling user and its comment names `learniq.gradeSubmission`
<!-- @e2e exclude Backend record content, no UI; pinned by tests/Unit/Service/LearniqAgentToolsTest.php. -->

### Requirement: The expiring-credentials read is a closed, minimised projection (REQ-011)
`learniq.listExpiringCredentials` MUST be the only credential-reading tool, scope `read`, and MUST return per row exactly: `credentialId`, `learnerId`, `learnerDisplayName`, `courseId`, `courseTitle`, `expiresAt` and `renewalCourseSlug`. It MUST NOT return `edciPayload`, `openbadges3Payload`, `signature`, `walletAttestationRef`, `verificationUrl`, any wallet field, or any `LearnerProfile` field. The list is closed: adding a field needs a spec change argued against `scholiq-mcp-adoption` REQ-003. Results MUST be read with the caller's own rights, so an agent sees only credentials its human principal may see.

#### Scenario: The renewal sweep works on minimised data alone
- GIVEN credentials expiring before a date exist
- WHEN an agent runs `learniq.listExpiringCredentials(before)` and then `learniq.enrolLearner` per row into the renewal course
- THEN each row carries exactly the seven fields and nothing more
- AND no wallet, signature or payload field crossed the MCP boundary
<!-- @e2e exclude Agent-to-app call, no UI; pinned by tests/Unit/Service/LearniqAgentToolsTest.php. -->

#### Scenario: No derived credential tool exists beside the curated read
- GIVEN the learniq register
- WHEN it is inspected for MCP declarations
- THEN the `credential` schema declares none
<!-- @e2e exclude Register shape, no UI; pinned by tests/Unit/Register/McpDialectRegisterTest.php. -->
