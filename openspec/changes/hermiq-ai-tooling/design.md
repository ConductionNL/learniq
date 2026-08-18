# Design: hermiq-ai-tooling

## Context

After `scholiq-mcp-adoption`, Scholiq's MCP surface is 12 derived, read-only tools over 6 curated schemas; every write verb and every learner-personal-data schema is refused, and `lib/Mcp/` is empty. The refusal of writes was mechanism-specific: the `x-openregister-mcp` dialect cannot express preconditions ("only a published course", "only within the submission window"), so a dialect write verb is an ungoverned write. Nothing in that change argues against writes that run **inside** Scholiq's guarded services.

The fleet has since converged on the pattern for governed write tools:

- **decidesk** (`lib/Mcp/`: `McpMeetingGate`, `McpArgumentValidator`, `McpMeetingScopeResolver`, `McpMeetingTools`) — write tools as thin wrappers: validate arguments, resolve caller scope, delegate to guarded service logic, attribute the write.
- **hermiq** — the governor: `ToolClassificationService` classifies every tool on scope × reach; `ToolReachResolver` defines reach as `self` / `user` / `instance` / `external` (`REACH_*` constants, verified); per-agent grants are default-deny for anything non-read; `ApprovalService` implements human approval gates; guardrail policies can force `confirm`/`deny` per tool; runs are audited.
- **openregister** — `AttributeToolScanner` discovers `#[McpTool]` methods on services listed by an `IMcpScannableServices` implementation; validated hints: `scope` ∈ `read|create|update|delete`, `readOnlyHint`/`destructiveHint`/`idempotentHint`.

The PO's framing: every app action should in principle be automatable; rights are granted per agent, granularly; chat is a command surface even before autonomy.

## Goals / Non-Goals

**Goals**
- The four highest-value Scholiq write actions callable by a governed agent: enrol, record attendance, grade, issue credential.
- The canonical chat scenario end-to-end: *"Which certificates expire this quarter? Re-enrol those people."*
- Every write triple-gated: Hermiq grant (default-deny) → human approval where certifying → Scholiq server-side guards + ADR-023.
- Honest classification: reach and scope declared per tool so Hermiq's UI shows the true blast radius.

**Non-Goals**
- Destructive scopes (`update`/`delete`: revoke, un-enrol, regrade-by-overwrite) — separate change.
- Widening the derived read surface (REQ-001…REQ-005 of `scholiq-mcp-adoption` untouched except REQ-006).
- Any Hermiq-side code.

## Decisions

### Decision 1: Curated `#[McpTool]` service methods, not dialect write verbs, not a provider

An `IMcpToolProvider` would shadow the derived tools (the exact failure `scholiq-mcp-adoption` REQ-006 exists to prevent) and ADR-063 forbids it. Dialect write verbs are ungoverned (no preconditions). `#[McpTool]` methods on a service are the sanctioned third path: discovered by `AttributeToolScanner`, no shadowing (distinct 2-segment ids), and they execute inside Scholiq where the guards live. REQ-006 is MODIFIED accordingly: still no `IMcpToolProvider`, but curated `#[McpTool]` methods are now explicitly permitted under the rules in this change's requirements.

### Decision 2: The tool table

| Tool id | Scope | Reach | Approval gate | Delegates to (existing, unchanged) | Rationale for reach |
|---|---|---|---|---|---|
| `scholiq.enrolLearner` | create | instance | no | enrolment write path incl. `CohortMembershipGuard`; ADR-023 action `mcp.enrol-learner` | Affects another user's learning duties inside this instance; reversible; not certifying. |
| `scholiq.recordAttendance` | create | instance | no | attendance write path (`AttendanceRecord` + `AttendanceFlagCreationHandler` untouched); action `mcp.record-attendance` | An administrative fact about another user; correctable. |
| `scholiq.gradeSubmission` | create | instance | **yes** | `AssessmentGradeGuard`-guarded grade-entry path; action `mcp.grade-submission` | A grade has BSA/progression consequences; a human examiner must approve each proposed grade set. |
| `scholiq.issueCredential` | create | **external** | **yes** | `CredentialSigningService` + issuance flow (same path as `external-training.issue-credential`); action `mcp.issue-credential` | A signed credential propagates beyond the instance (verification URL, wallet offer, EDCI/OpenBadges payloads) — issuance is not un-distributable. |
| `scholiq.listExpiringCredentials` | read | instance | no | credential query with fixed projection (see Decision 4) | Reads other users' credential metadata, minimised. |

`reach: external` on `issueCredential` is the load-bearing honesty: Hermiq surfaces external-reach writes with its strongest warnings and default-deny posture.

### Decision 3: Approval gates are enforced in the tool path, not only in Hermiq

Hermiq's `ApprovalService` is the UX for approval, but Scholiq must not assume every MCP client is Hermiq. `gradeSubmission` and `issueCredential` therefore run **two-phase in Scholiq**: phase 1 validates and stages a proposal object (no domain write), returning a staged-proposal reference; phase 2 executes only with an approval token that Scholiq verifies was minted for a human approver distinct from the acting agent's grant. When Hermiq is the client its approval flow supplies the token; a non-Hermiq client without a token simply cannot pass phase 2. Enrol/attendance are single-phase.

### Decision 4: The minimised credential read is a closed projection

`listExpiringCredentials(before, courseId?, renewableOnly?)` returns rows of exactly: `credentialId`, `learnerDisplayRef` (display name via the profile, never `bsnEncrypted`/`birthDate`/ids beyond the opaque learner object id needed for `enrolLearner`), `courseId` + course name, `expiresAt`, `renewalCourseSlug` (from the course's `renewalCourseSlug`, verified property). It never returns `edciPayload`, `openbadges3Payload`, `signature`, `walletAttestationRef`, `verificationUrl`, or any wallet field. This answers "which certificates expire this quarter" and feeds `enrolLearner` directly, while keeping REQ-003's refusal of whole-object credential reads intact. The projection list is normative (REQ-011); additions require a spec change.

### Decision 5: Attribution — the agent principal is a first-class audit field

Every tool write records: NC user (the grant owner), agent identity (Hermiq agent id from the MCP session context), tool id, staged-proposal ref where applicable, and approval token id where applicable. "Who graded this?" must never answer with only a human name when an agent proposed it. Audit rows go through OpenRegister's audit trail exactly as UI writes do, plus the agent fields.

### Decision 6: Chat scenarios as verification fixtures

1. **Renewal sweep** — "Which certificates expire this quarter? Re-enrol those people." → `listExpiringCredentials(before: quarter-end)` → per learner `enrolLearner(learnerId, renewal course)` → summary. No approval gate (enrolment), full audit.
2. **Attendance by voice/chat** — "Record attendance for today's 9:00 session: everyone present except Jayden (sick, excused)." → derived `scholiq.session.search` (existing) → `recordAttendance` per learner. Excuse handling stays in the existing `ExcuseRequest` flow — the tool records status only.
3. **Gated grading** — "Grade the submitted week-3 assignments per the rubric and put them up for my approval." → agent stages `gradeSubmission` proposals → teacher approves the batch in Hermiq → phase 2 executes → grades visible in the normal UI.

## Risks / Trade-offs

- Two-phase writes add a staged-proposal object type (small, internal, no register schema exposure) — accepted for gate integrity.
- One-call-per-learner enrolment is chatty; accepted for audit granularity (Open Question in proposal).

## Migration Plan

Additive. Ships after `scholiq-mcp-adoption`; a single release may carry both. Rollback = revert (staged proposals are inert without the tool class).

## Open Questions

Carried in proposal.md (temporal precondition on attendance; batch enrolment; AVG verwerkingsregister entry for agent-initiated issuance).
