# Proposal: hermiq-ai-tooling

## Summary

Extend Scholiq's MCP surface from "read-only catalogue" to **full action coverage under Hermiq governance**. `scholiq-mcp-adoption` (which this change builds on and does not duplicate) gives agents 12 derived read tools over 6 curated schemas and deliberately refuses every write verb, because the `x-openregister-mcp` dialect cannot express preconditions. This change adds the writes the product owner actually wants automated — **enrol, record attendance, grade, issue credential** — as curated `#[McpTool]` service methods with hard server-side gates, plus one field-minimised read (`listExpiringCredentials`) that the derived surface can never safely provide. Governance is Hermiq's existing model: every tool is classified on **scope** (`read`/`create`/`update`/`delete`) × **reach** (`self`/`user`/`instance`/`external`, per `ToolReachResolver`), write grants are **default-deny per agent**, grading and credential issuance carry a **mandatory human approval gate**, and every invocation lands in the audit trail with the agent principal attributed. Even without automation, this makes chat a command surface for the app: "record attendance for today's 9:00 session" becomes a governed tool call instead of twelve clicks.

## Motivation

- **PO framing (fleet-wide):** every app provides MCP tooling for all its actions so that any action can in principle be automated by an AI agent; users grant rights per agent, granularly. Scholiq today covers reads only (after `scholiq-mcp-adoption`), so the highest-value flows — the ones the PO's canonical chat scenario needs ("which certificates expire this quarter? re-enrol those people") — are impossible.
- **The read-only refusal was about the dialect, not about writes.** `scholiq-mcp-adoption` REQ-002's rationale is that a derived `update` cannot express "only when lifecycle allows it". Curated `#[McpTool]` service methods CAN: they run inside Scholiq services where `AssessmentGradeGuard`, `CohortMembershipGuard`, `SubmissionWindowGuard` and the ADR-023 `ActionAuthService` already live. The fleet reference for exactly this pattern is `decidesk/lib/Mcp/` (`McpMeetingGate`, `McpArgumentValidator`, `McpMeetingScopeResolver`): write tools as thin, gated wrappers over guarded service logic.
- **The expiring-certificates question is unanswerable today by design.** `credential` is on the hard-refusal list (REQ-003) because a derived `get` returns the whole object (learner id, wallet payloads, signatures). The scenario needs exactly three fields. A curated read with a fixed, minimised projection honours the refusal's intent while serving the question.
- **Hermiq is already the governor.** Hermiq classifies tools by scope/reach (`ToolClassificationService`, `ToolReachResolver`), holds per-agent grants (default-deny for writes), runs approval gates (`ApprovalService`) and guardrail policies. Scholiq's job is to publish honestly-labelled tools and enforce its own domain gates server-side; it MUST NOT trust the agent layer to be the only gate.

## Affected Projects

- [ ] Project: scholiq — new `lib/Mcp/ScholiqAgentTools.php` (`#[McpTool]` methods delegating to services), `lib/Mcp/ScholiqScannableServices.php` (`IMcpScannableServices`), `lib/Mcp/McpArgumentValidator.php`, ADR-023 action-matrix rows, tests
- [ ] Project: openregister — **no code change**; `AttributeToolScanner` + `IMcpScannableServices` (present at `origin/development`) discover the methods
- [ ] Project: hermiq — **no code change**; consumes declared `scope`/`reach` hints exactly as it does for decidesk

## Capabilities

- `mcp-tool-surface` — extended: the write-action tools, their gates, and the curated minimised read join the capability created by `scholiq-mcp-adoption`

## Scope

### In Scope

- Five curated tools on a new `ScholiqAgentTools` service, discovered via `IMcpScannableServices`:
  - `scholiq.enrolLearner` — create an `enrolment` (scope `create`, reach `instance`)
  - `scholiq.recordAttendance` — create an `attendance-record` for a session (scope `create`, reach `instance`)
  - `scholiq.gradeSubmission` — write a `grade-entry` (scope `create`, reach `instance`, **approval-gated**)
  - `scholiq.issueCredential` — issue a `credential` (scope `create`, reach `external` — a signed credential leaves the instance via wallets/verification URLs, **approval-gated**)
  - `scholiq.listExpiringCredentials` — scope `read`, fixed minimised projection (learner display ref, course, `expiresAt`, renewal course), never the full object
- Server-side argument validation (`McpArgumentValidator` pattern from decidesk) and delegation to the existing guarded services — every existing lifecycle guard keeps running unchanged
- ADR-023 action rows in `lib/actions.seed.json` for the four writes, seeded admin-only
- Agent-principal attribution: every write records the acting agent identity in the OpenRegister audit trail alongside the NC user
- 2–3 documented chat scenarios as e2e/verification fixtures

### Out of Scope

- Any `x-openregister-mcp` write verb on any schema — the dialect surface stays read-only exactly as `scholiq-mcp-adoption` REQ-002 pins it
- Exposing any further learner-personal-data schema to derived reads (REQ-003's OFF list is untouched)
- `update`/`delete` scope tools (revoking credentials, deleting grades, un-enrolling) — deliberately deferred; destructive scopes need their own change with a stronger case
- Hermiq-side UI, grant management, or approval-flow changes (all exist)
- Autonomous scheduling of these tools (Hermiq schedules; Scholiq only serves tools)

## Approach

1. Depend on `scholiq-mcp-adoption` landing first (this change modifies its REQ-006 and continues its REQ numbering).
2. Add `ScholiqAgentTools` with the five `#[McpTool]`-annotated methods, each declaring honest `scope`, `reach`, `destructiveHint`, `idempotentHint`, and description prose that names the approval gate where one applies.
3. Register `ScholiqScannableServices` under `OCA\OpenRegister\Mcp\IMcpScannableServices::scholiq`.
4. Route every write through the existing service/guard path (`AssessmentGradeGuard`, `CohortMembershipGuard`, `CredentialSigningService`, attendance flow) plus `ActionAuthService::requireAction()` — the tool layer adds argument validation and attribution, never bypasses a gate.
5. Verify Hermiq classifies the tools as write/approval-gated from their declared hints (as it already does for decidesk's write tools).

## New Dependencies

None. OpenRegister and Hermiq integration points all exist; decidesk is a reference, not a dependency.

## Impact

- Tool count grows from 12 (derived reads) to 17 (12 + 4 writes + 1 curated read).
- `lib/actions.seed.json` gains 4 rows (admin-only seed; admins broaden per ADR-023).
- No schema property changes; no register re-import behaviour change beyond none.
- New PHP under `lib/Mcp/` — permitted again by this change's modification of REQ-006 (which currently says no `#[McpTool]` is needed; it becomes "no `IMcpToolProvider`, curated `#[McpTool]` methods allowed under these rules").

## Cross-Project Dependencies

- **scholiq-mcp-adoption** MUST be merged first (REQ numbering and REQ-006 modification depend on it).
- **openregister** ≥ the commit carrying `AttributeToolScanner` + `IMcpScannableServices` (present at `origin/development`).
- **hermiq** ≥ the commit honouring declared hints on 2-segment curated tool ids (hermiq #57, merged).

## Risks

### Risk 1: A write tool becomes a gate bypass

- **Severity**: High
- **Detail**: If `gradeSubmission` writes the `grade-entry` object directly via ObjectService, it could skip `AssessmentGradeGuard` (which enforces who may grade what, when).
- **Mitigation**: REQ-008 makes delegation-to-the-guarded-path a spec requirement with a test that a guard-rejected write fails identically through MCP and through the UI. The tool layer owns argument shape and attribution only.

### Risk 2: Approval fatigue turns the gate into a rubber stamp

- **Severity**: Medium
- **Detail**: "Re-enrol those 40 people" as 40 separate approvals trains the approver to click through.
- **Mitigation**: Enrolment is deliberately NOT approval-gated (it is reversible and non-certifying); only grading and credential issuance — the two acts with legal/certifying weight — carry the gate, and `issueCredential` accepts a batch reference so one approval covers one reviewed batch.

### Risk 3: The minimised read drifts toward a full credential read

- **Severity**: Medium
- **Detail**: Field-by-field additions ("can we also have the wallet status?") would recreate the exposure REQ-003 refuses.
- **Mitigation**: REQ-011 fixes the projection as a closed list; adding a field requires a spec change against REQ-003's rationale.

### Risk 4: Prompt-injected chat issues a credential

- **Severity**: High
- **Mitigation**: Three independent layers must all fail: Hermiq's per-agent grant (default-deny for writes), the human approval gate on the two certifying writes, and Scholiq's server-side guards + ADR-023 matrix. The spec requires the approval gate to be enforced server-side in the tool path, not only in Hermiq's UI.

## Rollback Strategy

Revert the commit. The scannable-services registration and the tool class disappear; the derived read surface from `scholiq-mcp-adoption` is untouched. Action-matrix rows for the removed tools are ignored by `ActionAuthService` once no caller names them. No data migration.

## Open Questions

- Should `recordAttendance` require the session to be `in-progress`/`completed` (temporal precondition) or accept back-dated corrections? Current UI allows corrections; the tool starts with the same behaviour.
- Batch semantics for `enrolLearner`: one call per learner (auditable, chatty) vs. a `learnerIds[]` argument (efficient, coarser audit). Starting with one-per-call; revisit with real usage.
- Does agent-initiated credential issuance need its own entry in `openspec/specs/avg-verwerkingsregister/` as a new processing activity?
