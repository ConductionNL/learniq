# Tasks: hermiq-ai-tooling

> Depends on `scholiq-mcp-adoption` being merged first (modifies its REQ-006; continues its REQ numbering).

> **Round 5 (2026-09-28): built in part, the rest superseded.** Stacked on `scholiq-mcp-adoption` (PR #1252).
> Built: `learniq.enrolLearner`, `learniq.recordAttendance`, `learniq.gradeSubmission` and
> `learniq.listExpiringCredentials` on `lib/Service/LearniqAgentTools.php`, opted in by
> `lib/Mcp/LearniqScannableServices.php`, gated by `mcp.*` rows in `lib/actions.seed.json`, tested in
> `tests/Unit/Service/LearniqAgentToolsTest.php`.
> Superseded, not built:
> - **`issueCredential`**: a credential is signed and leaves the instance when it exists, the schema has no draft
>   state, and the round rule "every AI-assisted action is a draft the teacher accepts" rules out an agent issuing
>   one. It needs a draft state on the credential first (own change).
> - **Learniq-side two-phase staging with approval tokens (Task 3 as written)**: hermiq's agent-guardrails already
>   gate a confirm-classified tool call on an approved, unconsumed Approval (`ApprovalService::
>   findApprovedUnconsumedToolCallApproval`), and the tool never receives an approval id to verify. The server-side
>   gate in learniq is the grade's own lifecycle: the tool writes a `concept`, and only a teacher's `publish`
>   (guarded by `ReportPeriodLockGuard`) makes it count, the same step the marking screen uses.
> - **Agent identity on the record (Task 4 as written)**: learniq does not receive the agent id. OpenRegister's
>   attribute tool provider writes one audit entry per call (tool id + calling user) and hermiq's run log names
>   the agent; learniq stamps the calling user and a note naming the tool on the record.
> - **`reach` metadata**: OpenRegister's `#[McpTool]` has no `reach` parameter; hermiq's `ToolReachResolver`
>   derives it.
> Paths: `Scholiq*` names became `Learniq*`; there is no `CHANGELOG.md` (the docs page is the record).

## Implementation Tasks

### Task 1: `ScholiqAgentTools` with five `#[McpTool]` methods + scannable-services registration (must / MVP)
- **spec_ref**: `openspec/specs/mcp-tool-surface/spec.md#requirement-write-actions-are-curated-tools-with-declared-scope-and-reach-req-007`
- **files**: `lib/Mcp/ScholiqAgentTools.php` (new), `lib/Mcp/ScholiqScannableServices.php` (new), `lib/Mcp/McpArgumentValidator.php` (new, decidesk pattern), `lib/AppInfo/Application.php`
- **acceptance_criteria**:
  - GIVEN the app boots WHEN the container resolves `OCA\OpenRegister\Mcp\IMcpScannableServices::scholiq` THEN it returns `[ScholiqAgentTools::class]`, and no `IMcpToolProvider` alias exists
  - GIVEN the tool catalogue WHEN enumerated THEN it contains exactly 17 scholiq tools: the 12 derived reads plus `enrolLearner`, `recordAttendance`, `gradeSubmission`, `issueCredential`, `listExpiringCredentials`, all 2-segment ids
  - GIVEN each curated tool WHEN its metadata is read THEN scope/reach/hints match the REQ-007 table (`issueCredential` = reach `external`), and the descriptions of the two gated tools name the approval gate
  - GIVEN the new PHP WHEN scoped PHPCS/`composer check:strict` runs THEN it is clean
- [x] Implement
- [x] Test

### Task 2: ADR-023 action rows + delegation to the guarded service paths (must / MVP) — BLOCKS Task 3
- **spec_ref**: `openspec/specs/mcp-tool-surface/spec.md#requirement-every-write-tool-delegates-to-the-existing-guarded-path-and-cannot-bypass-a-gate-req-008`
- **files**: `lib/actions.seed.json`, `lib/Mcp/ScholiqAgentTools.php`
- **acceptance_criteria**:
  - GIVEN `lib/actions.seed.json` WHEN read THEN it contains `mcp.enrol-learner`, `mcp.record-attendance`, `mcp.grade-submission`, `mcp.issue-credential`, each seeded `["admin"]`
  - GIVEN a caller not granted the action WHEN any write tool is invoked THEN `ActionAuthService::requireAction()` denies before any domain logic runs
  - GIVEN a fixture where `AssessmentGradeGuard` rejects the grade WHEN `gradeSubmission` is invoked THEN the MCP error equals the UI-path error and no object is written (gate-parity test)
  - GIVEN any write tool WHEN its implementation is reviewed THEN it contains no direct ObjectService write — only delegation to the existing enrolment/attendance/grading/issuance services
- [x] Implement
- [x] Test

### Task 3: Two-phase approval for `gradeSubmission` and `issueCredential` (must / MVP)
- **spec_ref**: `openspec/specs/mcp-tool-surface/spec.md#requirement-grading-and-credential-issuance-are-two-phase-with-a-server-verified-human-approval-req-009`
- **files**: `lib/Mcp/ScholiqAgentTools.php`, `lib/Service/` (staged-proposal handling), `tests/Unit/Mcp/`
- **acceptance_criteria**:
  - GIVEN phase 1 of either gated tool WHEN it completes THEN a staged proposal exists, no domain object was written, and the response carries the proposal reference
  - GIVEN phase 2 WHEN invoked without a valid approval token, with an expired token, or with a token minted for the acting agent itself THEN it is refused and no domain write occurs
  - GIVEN phase 2 WHEN invoked with a valid human-approver token THEN the domain write executes through the guarded path of Task 2
  - GIVEN `enrolLearner` and `recordAttendance` WHEN invoked THEN they are single-phase (no token required)
- [ ] Implement (superseded, see the note at the top)
- [ ] Test (superseded)

### Task 4: Agent-principal attribution in the audit trail (must / MVP)
- **spec_ref**: `openspec/specs/mcp-tool-surface/spec.md#requirement-every-agent-write-is-attributed-to-the-agent-principal-in-the-audit-trail-req-010`
- **files**: `lib/Mcp/ScholiqAgentTools.php`, `tests/Unit/Mcp/`
- **acceptance_criteria**:
  - GIVEN any tool-path write WHEN the object's audit trail is read THEN it carries agent identity, granting user, tool id, and (for gated writes) proposal reference + approval token id
  - GIVEN the same write performed through the UI WHEN audited THEN it carries no agent fields (control: attribution is tool-path-specific, not global noise)
- [ ] Implement (superseded, see the note at the top)
- [ ] Test (superseded)

### Task 5: `listExpiringCredentials` minimised projection (must / MVP)
- **spec_ref**: `openspec/specs/mcp-tool-surface/spec.md#requirement-the-expiring-credentials-read-is-a-closed-minimised-projection-req-011`
- **files**: `lib/Mcp/ScholiqAgentTools.php`, `tests/Unit/Mcp/`
- **acceptance_criteria**:
  - GIVEN seeded credentials WHEN the tool runs with `before` THEN each row contains exactly the six REQ-011 fields and nothing else (assert key set equality, not just presence)
  - GIVEN a credential the granting user may not read (RBAC) WHEN the tool runs THEN that credential is absent
  - GIVEN the catalogue WHEN enumerated THEN no `scholiq.credential.*` derived tool exists
- [x] Implement
- [x] Test

### Task 6: Chat-scenario verification + docs (must / MVP)
- **spec_ref**: `openspec/specs/mcp-tool-surface/spec.md#requirement-grading-and-credential-issuance-are-two-phase-with-a-server-verified-human-approval-req-009`
- **files**: `tests/e2e/spec-coverage/hermiq-ai-tooling.spec.ts` (new), `docs/`, `CHANGELOG.md`
- **acceptance_criteria**:
  - GIVEN the e2e suite WHEN it runs THEN the renewal-sweep flow (list → enrol) and the gated-grading flow (stage → approve → grade visible in UI) pass against seeded data
  - GIVEN `docs/` WHEN read THEN it records the tool table (scope × reach × gate), the three chat scenarios, and the refusal of destructive scopes
  - GIVEN `CHANGELOG.md` WHEN read THEN it records the new write surface and its governance
- [x] Implement (docs/Technical/agent-tools.md records the tools, the gates and the chat scenarios; no CHANGELOG.md exists)
- [ ] Test (the Playwright chat flows need hermiq driving an agent against an instance; not possible in this lane)
  - r5-live, 2026-09-29, shared dev instance, still open: hermiq is installed, but nothing drives an agent on the shared instance.

## Verification
- [ ] All tasks checked off
- [x] `openspec validate hermiq-ai-tooling --type change --strict` passes
- [ ] Manual testing against acceptance criteria (a denied grant, a rejected proposal, an approved batch)
  - r5-live, 2026-09-29, shared dev instance, still open: needs hermiq driving an agent, not available here.
- [ ] Code review against spec requirements

## Tests (company-wide ADR-009)
- [x] PHPUnit: tool metadata, gate parity, attribution note, projection key-set in `tests/Unit/Service/LearniqAgentToolsTest.php` (the two-phase state machine is superseded); zero failures in the full suite
- [ ] Browser tests (Playwright MCP): `tests/e2e/spec-coverage/hermiq-ai-tooling.spec.ts` (Task 6)
- [ ] All tests pass
- Newman/Postman: N/A — no Scholiq HTTP endpoint is added; the MCP surface is served by OpenRegister's `/api/mcp`.

## Documentation (company-wide ADR-010)
- [x] `docs/` records the governed write surface, gates, and chat scenarios (Task 6): `docs/Technical/agent-tools.md`
- Screenshots: N/A for Scholiq UI (no new Scholiq surface); the approval flow lives in Hermiq.

## i18n (company-wide ADR-005)
- N/A — tool descriptions and staged-proposal fields are agent-facing/backend; no new Scholiq UI copy. Approval-flow copy is Hermiq's.
