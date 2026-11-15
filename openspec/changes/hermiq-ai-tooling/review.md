# Review: hermiq-ai-tooling

Reviewer: r5 spec-review lane, 2026-09-29, against `origin/development` at the branch point of `chore/r5-spec-reviews`.
Scope: every requirement and scenario in `specs/mcp-tool-surface/spec.md` (REQ-006 modified, REQ-007 to REQ-011 added: 6 requirements, 9 scenarios). The superseded parts (issueCredential, learniq-side approval tokens, agent identity on the record) are no longer in the spec delta; their task boxes are marked superseded in tasks.md.

## What was checked

- `lib/Service/LearniqAgentTools.php`: four `#[McpTool]` methods, `enrolLearner` (:138), `recordAttendance` (:191), `gradeSubmission` (:250), `listExpiringCredentials` (:315). Every write tool calls `authorise()` (:409, `ActionAuthService::requireAction()`) first and writes through `write()` (:434, `ObjectService::saveObject()` with default `_rbac=true`). Reads use `ObjectService::find`/`findAll` with default RBAC on (OpenRegister `lib/Service/ObjectService.php`, `$_rbac=true` defaults).
- `lib/Mcp/LearniqScannableServices.php:47` returns `[LearniqAgentTools::class]`; `lib/AppInfo/Application.php:141` registers it as `IMcpScannableServices::learniq`.
- `lib/actions.seed.json:20-22`: `mcp.enrol-learner`, `mcp.record-attendance`, `mcp.grade-submission`, each `["admin"]`.
- The Credential schema carries no `x-openregister-mcp` key.

## Defect found and fixed in this PR

`listExpiringCredentials` read one page of 200 issued credentials and filtered that page on `expiresAt`. The page is not ordered by expiry, and `Credential.expiresAt` is nullable ("Null = does not expire"), so on an instance with more than 200 issued credentials every expiring certificate past row 200 was silently left out. The renewal sweep (REQ-011's scenario) would then re-enrol only part of the people it was asked about, and report success. The unit test could not see it because its `findAll` double ignored `limit`.

Fix: the tool now scans issued credentials page by page (200 per page, at most 25 pages), keeps at most 200 expiring rows, and returns `truncated: true` when it stopped early, so the agent knows the list is partial. The tool description and `docs/Technical/agent-tools.md` say so. The row projection is unchanged (still exactly the seven REQ-011 fields). The `findAll` double now honours `limit` and `offset`; `testExpiringCredentialsPastTheFirstPageAreFound` and `testTooManyExpiringCredentialsSayTruncated` were red against the old code (3 failures) and are green with the fix.

## Test gaps closed in this PR

- REQ-006 scenario "registers no tool provider but does register scannable services": the test only called `getScannableServiceClasses()`. Added `testTheAppRegistersScannableServicesAndNoToolProvider` (the alias in `Application.php`, no `mcpProvider`, no `IMcpToolProvider` implementation in `lib/`).
- REQ-008 scenario "no object is read or written": the test asserted no writes only. The double now counts reads and `testActionMatrixGatesBeforeAnyWrite` asserts zero.
- REQ-010 scenario "grader is the calling user and the comment names the tool": no assertion existed for the grade. `testGradeIsAConceptAndGuardRefusalsPassThrough` now asserts `grader` and the tool note; the attendance test asserts the note too.

## Review table

| Requirement / scenario | Implementing file:line | Test file::method | Verdict |
|---|---|---|---|
| REQ-006 (MODIFIED) No hand-written MCP tool code; curated `#[McpTool]` methods allowed | `lib/Mcp/LearniqScannableServices.php:47`; `lib/AppInfo/Application.php:141`; attributes at `LearniqAgentTools.php:116, 168, 228, 289` (2-segment names, scope, subject, action, three hints) | `tests/Unit/Service/LearniqAgentToolsTest.php::testToolsAreDeclaredWithScopeAndHints`, `::testTheAppRegistersScannableServicesAndNoToolProvider` | MET (registration test added in this PR) |
| Scenario: The derived tools are not shadowed | no provider; derived Course tools declared | `tests/Unit/Register/McpDialectRegisterTest.php::testTheSurfaceIsReadOnly`, `LearniqAgentToolsTest::testTheAppRegistersScannableServicesAndNoToolProvider` | MET |
| Scenario: The app registers no tool provider but does register scannable services | `Application.php:141`, `LearniqScannableServices.php:47` | `LearniqAgentToolsTest::testTheAppRegistersScannableServicesAndNoToolProvider`, `::testToolsAreDeclaredWithScopeAndHints` | MET (test added in this PR) |
| REQ-007 Exactly three write tools with declared scope | `LearniqAgentTools.php:116` (enrolment/create), `:168` (attendance/create), `:228` (grade/propose), all `scope: create`, `readOnlyHint: false`; no `issueCredential` | `LearniqAgentToolsTest::testToolsAreDeclaredWithScopeAndHints` (exact set of four tools, three non-read) | MET |
| Scenario: The catalogue carries exactly three write tools with honest metadata | same | same | MET |
| REQ-008 Every write tool delegates to the guarded path | `authorise()` first at `:139`, `:192`, `:251`; reads via `read()`/`first()` with RBAC; writes via `write()` :434, `saveObject` RBAC on; refusals caught and returned as `refused` | `LearniqAgentToolsTest::testActionMatrixGatesBeforeAnyWrite`, `::testGradeIsAConceptAndGuardRefusalsPassThrough`, `::testEnrolIsPendingAndIdempotent` (asserts RBAC flag) | MET |
| Scenario: A guard rejection reaches the agent unchanged | `write()` catch block | `LearniqAgentToolsTest::testGradeIsAConceptAndGuardRefusalsPassThrough` | MET |
| Scenario: The ADR-023 matrix gates the tool before any domain logic | `authorise()` :409; seed rows `lib/actions.seed.json:20-22` | `LearniqAgentToolsTest::testActionMatrixGatesBeforeAnyWrite` (zero reads, zero writes) | MET (zero-read assertion added in this PR) |
| REQ-009 An agent grade is a concept a teacher publishes | `gradeSubmission` :250 writes `lifecycle: concept`, `sourceKind: assignment-submission`; `enrolLearner` writes `pending`; `recordAttendance` writes or corrects one record | `LearniqAgentToolsTest::testGradeIsAConceptAndGuardRefusalsPassThrough`, `::testEnrolIsPendingAndIdempotent`, `::testAttendanceUpsertsAndRejectsUnknownStatus` | MET |
| Scenario: An agent-proposed grade stays invisible until a teacher publishes it | same; visibility of a concept is the gradebook's existing rule | `LearniqAgentToolsTest::testGradeIsAConceptAndGuardRefusalsPassThrough` | MET |
| REQ-010 Every agent write says it was made by a tool | `agentNote()` in the `reason`/`comment` of every write; `markedBy` and `grader` set to the calling uid | `LearniqAgentToolsTest::testEnrolIsPendingAndIdempotent`, `::testAttendanceUpsertsAndRejectsUnknownStatus`, `::testGradeIsAConceptAndGuardRefusalsPassThrough` | MET (grade and attendance assertions added in this PR) |
| Scenario: A grade proposed by an agent is answerable as such | `gradeSubmission` :250 | `LearniqAgentToolsTest::testGradeIsAConceptAndGuardRefusalsPassThrough` | MET (assertion added in this PR) |
| REQ-011 The expiring-credentials read is a closed, minimised projection | `listExpiringCredentials` :315, `expiring()` :345, `project()`; `EXPIRING_FIELDS` :73 | `LearniqAgentToolsTest::testExpiringCredentialsAreAClosedProjection`, `::testExpiringCredentialsPastTheFirstPageAreFound`, `::testTooManyExpiringCredentialsSayTruncated` | MET with the fix in this PR (was PARTIAL: expiring rows past the first page of 200 were dropped) |
| Scenario: The renewal sweep works on minimised data alone | same | same | MET with the fix in this PR (was PARTIAL, same reason) |
| Scenario: No derived credential tool exists beside the curated read | Credential has no dialect key | `McpDialectRegisterTest::testOnlyTheCuratedSchemasOptIn` | MET |

## Totals

6 requirements, 9 scenarios: 15 rows MET. 2 of them (REQ-011 and its renewal-sweep scenario) were PARTIAL and are MET only with the fix in this PR. 0 NOT MET. The superseded task boxes are SUPERSEDED by decision (see the Round 5 note at the top of tasks.md).

## Observations (no action in this PR)

- `gradeSubmission` does not check that the submission is handed in (`submitted`, `late` or `returned`), although its description says "a handed-in submission". A draft can get a concept grade. A teacher still has to publish it, so nothing reaches a learner, and the spec does not require the check.
- `gradeSubmission` grades only `learnerIds[0]` of a group submission. That matches the marking screen (`src/views/MarkSubmissionView.vue:1047`), so it is the app's existing behaviour and not a tool defect.
- `enrolLearner` checks the course and that `learnerId` is not empty, but not that the learner exists; the enrolment write path's own validation is the only check.
- `recordAttendance` corrects an existing record under `scope: create`. REQ-009 permits it ("writes or corrects one record"), but hermiq will classify the correction as a create.
- The live boxes (chat flows, manual testing, browser tests, all tests pass) stay open: they need hermiq driving an agent.
