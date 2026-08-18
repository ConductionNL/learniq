# MCP Tool Surface — hermiq-ai-tooling delta

Extends the capability created by `scholiq-mcp-adoption` with governed write-action tools and one curated, field-minimised read. REQ-001…REQ-006 are claimed by `scholiq-mcp-adoption`; this delta modifies REQ-006 and adds REQ-007…REQ-011. The derived read surface (REQ-001…REQ-005) is untouched.

## MODIFIED Requirements

### Requirement: No hand-written MCP tool code remains in Scholiq (REQ-006)
Scholiq MUST NOT ship any `IMcpToolProvider` implementation and MUST NOT register an `mcpProvider` alias, because a hand-written provider tool takes precedence over a derived tool and would shadow the dialect surface. Scholiq MAY ship curated `#[McpTool]`-annotated methods on services listed by an `IMcpScannableServices` implementation registered under `OCA\OpenRegister\Mcp\IMcpScannableServices::scholiq`, and MUST do so only for capabilities the derived surface cannot safely provide: governed write actions (REQ-007…REQ-010) and field-minimised reads (REQ-011). Every curated tool id MUST be 2-segment (`scholiq.{toolName}`) so it can never collide with a derived `scholiq.{schema}.{verb}` id, and every curated tool MUST declare honest `scope`, `reach`, and hint metadata.

#### Scenario: The derived tools are not shadowed
- GIVEN the Scholiq register declares the dialect
- WHEN the MCP tool catalogue for app id `scholiq` is enumerated
- THEN `scholiq.course.search` and `scholiq.course.get` are present
- AND `scholiq.listCourses` and `scholiq.getCourseDetails` are absent
<!-- @e2e exclude backend catalogue enumeration — covered by PHPUnit against the scanner + derived provider; no UI surface -->

#### Scenario: The app registers no tool provider but does register scannable services
- GIVEN Scholiq is installed and enabled
- WHEN the container is asked for `OCA\OpenRegister\Mcp\IMcpToolProvider::scholiq`
- THEN no service is registered under that alias
- AND `OCA\OpenRegister\Mcp\IMcpScannableServices::scholiq` resolves to `ScholiqScannableServices` returning `[ScholiqAgentTools::class]`
<!-- @e2e exclude DI registration shape — covered by PHPUnit bootstrap assertions -->

## ADDED Requirements

### Requirement: Write actions are curated tools with declared scope and reach (REQ-007)
Scholiq MUST expose exactly four write-action tools as `#[McpTool]` methods on `ScholiqAgentTools`: `scholiq.enrolLearner` (scope `create`, reach `instance`), `scholiq.recordAttendance` (scope `create`, reach `instance`), `scholiq.gradeSubmission` (scope `create`, reach `instance`), and `scholiq.issueCredential` (scope `create`, reach `external`). Reach values MUST use Hermiq's `ToolReachResolver` vocabulary (`self`/`user`/`instance`/`external`). `issueCredential` MUST declare reach `external` because a signed credential propagates beyond the instance (verification URL, wallet offer, EDCI/OpenBadges payloads) and issuance is not un-distributable. No tool in this change may declare scope `update` or `delete`; destructive actions (revoke, un-enrol, overwrite-regrade) are refused pending their own change. Every tool description MUST name its approval gate where one applies, so an agent and its human principal see the gate before invoking.

#### Scenario: The catalogue carries exactly four write tools with honest metadata
- GIVEN the MCP tool catalogue for app id `scholiq`
- WHEN every tool with a non-read scope is inspected
- THEN exactly `scholiq.enrolLearner`, `scholiq.recordAttendance`, `scholiq.gradeSubmission`, `scholiq.issueCredential` are found
- AND each declares scope `create`, `readOnlyHint: false`, and the reach stated in this requirement
<!-- @e2e exclude backend catalogue metadata — covered by PHPUnit -->

#### Scenario: Hermiq classifies the certifying write as external-reach
- GIVEN Hermiq enumerates Scholiq's tools
- WHEN `scholiq.issueCredential` is classified
- THEN it is treated as a write with reach `external` and is default-denied until a per-agent grant exists
<!-- @e2e exclude cross-app classification — verified in hermiq's own suite (hint-honouring path, hermiq #57); Scholiq asserts only its declared metadata -->

### Requirement: Every write tool delegates to the existing guarded path and cannot bypass a gate (REQ-008)
Each write tool MUST validate its arguments (decidesk `McpArgumentValidator` pattern), call `ActionAuthService::requireAction()` with its own action id (`mcp.enrol-learner`, `mcp.record-attendance`, `mcp.grade-submission`, `mcp.issue-credential`, seeded admin-only in `lib/actions.seed.json` per ADR-023), and then delegate to the same service path the UI uses — `CohortMembershipGuard`, the attendance write flow, `AssessmentGradeGuard`, `CredentialSigningService` respectively — with every existing lifecycle guard running unchanged. A write that a guard rejects MUST fail through MCP with the same domain error it produces through the UI. The tool layer MUST NOT write objects directly through ObjectService.

#### Scenario: A guard rejection is identical over MCP and UI
- GIVEN a submission outside its window such that `SubmissionWindowGuard`/`AssessmentGradeGuard` rejects grading
- WHEN `scholiq.gradeSubmission` is invoked for it
- THEN the tool returns the same domain error the UI flow produces
- AND no grade-entry object is created
<!-- @e2e exclude backend gate parity — covered by PHPUnit invoking the tool method and the service path against the same fixture -->

#### Scenario: The ADR-023 matrix gates the tool before any domain logic
- GIVEN a caller whose groups are not granted `mcp.enrol-learner` in the action matrix
- WHEN `scholiq.enrolLearner` is invoked
- THEN `ActionAuthService::requireAction()` denies with a forbidden error
- AND no enrolment write is attempted
<!-- @e2e exclude backend authorization — covered by PHPUnit -->

### Requirement: Grading and credential issuance are two-phase with a server-verified human approval (REQ-009)
`scholiq.gradeSubmission` and `scholiq.issueCredential` MUST be two-phase: phase 1 validates and stages a proposal (no domain write) and returns a staged-proposal reference; phase 2 executes only when presented with an approval token that Scholiq verifies server-side was minted for a human approver distinct from the acting agent's grant owner where policy requires four-eyes, and in all cases distinct from the agent itself. The gate MUST be enforced in Scholiq's tool path, not delegated to the MCP client: a client that never presents a valid token can never reach the domain write. `enrolLearner` and `recordAttendance` MUST be single-phase (reversible, non-certifying — deliberately not approval-gated to avoid approval fatigue devaluing the two gates that matter).

#### Scenario: An unapproved grade proposal never becomes a grade
- GIVEN an agent stages a grade via `scholiq.gradeSubmission` phase 1
- WHEN no approval token is ever presented
- THEN no grade-entry object exists for the submission
- AND the staged proposal is visible for approval and expires per policy
<!-- @e2e exclude backend two-phase state machine — covered by PHPUnit -->

#### Scenario: The canonical gated-grading chat flow
- GIVEN a teacher asks their agent to grade submitted week-3 assignments per the rubric
- WHEN the agent stages proposals and the teacher approves the batch in Hermiq
- THEN phase 2 executes each approved proposal and the grades appear in the normal grading UI
- AND a proposal the teacher rejects is never executed
<!-- @e2e tests/e2e/spec-coverage/hermiq-ai-tooling.spec.ts -->

### Requirement: Every agent write is attributed to the agent principal in the audit trail (REQ-010)
Every write performed through a curated tool MUST record, alongside OpenRegister's normal audit fields: the acting agent identity (from the MCP session context), the granting NC user, the tool id, the staged-proposal reference where applicable, and the approval token id where applicable. An agent-proposed, human-approved grade MUST be answerable as such — never as a purely human act — from the audit trail alone.

#### Scenario: An approved credential issuance is traceable end to end
- GIVEN a credential issued via `scholiq.issueCredential` after approval
- WHEN the object's audit trail is read
- THEN it names the agent, the granting user, the tool id, and the approval token id
<!-- @e2e exclude backend audit assertion — covered by PHPUnit reading the audit trail after a tool-path write -->

### Requirement: The expiring-credentials read is a closed, minimised projection (REQ-011)
`scholiq.listExpiringCredentials` MUST be the only credential-reading tool, scope `read`, reach `instance`, and MUST return per row exactly: `credentialId`, `learnerDisplayRef`, `courseId`, course name, `expiresAt`, and `renewalCourseSlug`. It MUST NOT return `edciPayload`, `openbadges3Payload`, `signature`, `walletAttestationRef`, `verificationUrl`, any wallet field, `bsnEncrypted`, `birthDate`, or any other `LearnerProfile` field. This projection is normative and closed: adding a field requires a spec change argued against `scholiq-mcp-adoption` REQ-003, which continues to forbid any derived `credential` tool. Results MUST pass through OpenRegister RBAC as the granting user, so an agent sees only credentials its human principal may see.

#### Scenario: The renewal-sweep chat flow works on minimised data alone
- GIVEN credentials expiring within the quarter exist
- WHEN an agent runs `scholiq.listExpiringCredentials(before: quarter-end)` and then `scholiq.enrolLearner` per row into the renewal course
- THEN each row carried enough to enrol (learner ref, renewal course) and nothing more
- AND no wallet, signature, or payload field crossed the MCP boundary
<!-- @e2e tests/e2e/spec-coverage/hermiq-ai-tooling.spec.ts -->

#### Scenario: No derived credential tool exists beside the curated read
- GIVEN the MCP tool catalogue for app id `scholiq`
- WHEN it is enumerated
- THEN no `scholiq.credential.search` or `scholiq.credential.get` tool exists
- AND `scholiq.listExpiringCredentials` is the only tool naming credentials
<!-- @e2e exclude backend catalogue enumeration — covered by PHPUnit -->
