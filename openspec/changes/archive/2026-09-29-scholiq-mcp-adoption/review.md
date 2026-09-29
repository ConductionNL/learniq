# Review: scholiq-mcp-adoption

Reviewer: r5 spec-review lane, 2026-09-29, against `origin/development` at the branch point of `chore/r5-spec-reviews`.
Scope: `specs/mcp-tool-surface/spec.md` (6 requirements, 11 scenarios) and the REMOVED requirements in `specs/ai-companion-tools/spec.md` (5 requirements, 5 scenarios).

## What was checked

- `lib/Settings/learniq_register.json`: `x-openregister-mcp` appears exactly five times, under `configuration` of Course (line 1627), Lesson (2096), Regulation (4303), Programme (5527) and Assignment (8529). Each has `enabled: true`, tools `search` and `get` only, `scope: "read"`, `readOnlyHint: true`. Session has no `configuration` key.
- `authorization.read` on the five: Course (1598), Lesson (2067), Programme (5498), Assignment (8493): `instructors`, `hr`, `compliance-officers`, `team-leads` plus the lifecycle-matched `authenticated` entry (`$eq: published`; Assignment `$in: [published, closed]`). Regulation (4280): `compliance-officers`, `team-leads` plus `$eq: published`. No `admin` entry.
- OpenRegister (`origin/development`): `SchemaDerivedToolProvider` builds ids as `{appId}.{slug}.{verb}` (`lib/Mcp/BuiltIn/SchemaDerivedToolProvider.php:188`), and `Bootstrap::register()` registers `IMcpToolProvider::<appId>` only when the `mcpProvider` option is passed (`lib/AppHost/Bootstrap.php:238`). Learniq's `lib/AppInfo/Application.php:122` passes only `namespace` and `sectionName`.
- `lib/Mcp/` holds only `LearniqScannableServices.php` (added by hermiq-ai-tooling, an `IMcpScannableServices` opt-in, not an `IMcpToolProvider`). No `LearniqToolProvider`, no provider test, no `IMcpToolProvider` stub.
- Live evidence already in tasks.md (r5-live, 2026-09-29): a learner gets no draft courses from `course_search` and "not found" from `course_get` on a draft id; admin gets the draft.

## Gaps found and fixed in this PR

- REQ-004 names the exact filter list per schema; the test only checked that each filter is a property, so a dropped or added filter would pass. Added `McpDialectRegisterTest::testTheSearchFiltersAreTheSpecifiedLists`.
- REQ-005 names the exact staff groups and says `admin` MUST NOT be listed; the test only checked that each unconditional reader also writes the schema. Added `McpDialectRegisterTest::testTheUnconditionalReadersAreTheSpecifiedStaff`.
- REQ-006 scenario "The app registers no tool provider" had no test (the spec pointed at `McpDialectRegisterTest`, which does not look at the app registration). Added `tests/Unit/Service/LearniqAgentToolsTest.php::testTheAppRegistersScannableServicesAndNoToolProvider`: no `mcpProvider` option, no `IMcpToolProvider::` alias, no class in `lib/` implementing `IMcpToolProvider`.

## Review table: mcp-tool-surface

| Requirement / scenario | Implementing file:line | Test file::method | Verdict |
|---|---|---|---|
| REQ-001 Exactly five curated schemas declare the dialect | register lines 1627, 2096, 4303, 5527, 8529 | `tests/Unit/Register/McpDialectRegisterTest.php::testOnlyTheCuratedSchemasOptIn` | MET |
| Scenario: The derived catalogue contains only the curated schemas | same; OR derives only from schemas carrying the key | `McpDialectRegisterTest::testOnlyTheCuratedSchemasOptIn` | MET |
| Scenario: A schema without the dialect derives nothing | GradeEntry has no `configuration["x-openregister-mcp"]` | `McpDialectRegisterTest::testOnlyTheCuratedSchemasOptIn` | MET |
| REQ-002 The surface is read-only | the five `tools` blocks | `McpDialectRegisterTest::testTheSurfaceIsReadOnly` | MET |
| Scenario: No derived tool mutates a Learniq object | same | `McpDialectRegisterTest::testTheSurfaceIsReadOnly` | MET |
| REQ-003 No learner personal data and no exam content | only the five schemas opt in; Session, Cohort, Material, Item, ItemBank, Assessment, LearnerProfile, GradeEntry and the rest carry no key | `McpDialectRegisterTest::testOnlyTheCuratedSchemasOptIn` (exact set equality) | MET |
| Scenario: A learner-data schema derives no tool even for a single-object fetch | same | `McpDialectRegisterTest::testOnlyTheCuratedSchemasOptIn` | MET |
| Scenario: Exam item banks are unreachable | same | `McpDialectRegisterTest::testOnlyTheCuratedSchemasOptIn` | MET |
| REQ-004 Every declared search filter is a real property | the five `search.filters` lists | `McpDialectRegisterTest::testEverySearchFilterIsAProperty`, `::testTheSearchFiltersAreTheSpecifiedLists` | MET (exact-list test added in this PR) |
| Scenario: The register imports without a dialect validation error | same; OR `McpAnnotationValidator` | `McpDialectRegisterTest::testEverySearchFilterIsAProperty`, `::testTheSurfaceIsReadOnly` (the shape the validator checks); live import on the shared instance (tasks.md r5-live) | MET |
| REQ-005 Draft and archived content is not readable by non-admins | `authorization.read` at register lines 1598, 2067, 4280, 5498, 8493 | `McpDialectRegisterTest::testDraftsAreStaffOnly`, `::testTheUnconditionalReadersAreTheSpecifiedStaff` | MET (exact-group and no-admin test added in this PR) |
| Scenario: A non-admin search returns no draft courses | Course `authorization.read` | `McpDialectRegisterTest::testDraftsAreStaffOnly` (rule shape); live: learner `course_search` returned no drafts (tasks.md r5-live) | MET |
| Scenario: A non-admin get on a draft course is denied | same | same; live: learner `course_get` on a draft id answered "not found" | MET |
| Scenario: An admin still sees drafts | OpenRegister admin bypass | live: admin `course_search` lifecycle=draft returned the draft | MET (no unit test can prove the bypass; live evidence recorded) |
| REQ-006 No hand-written MCP tool code remains | `lib/AppInfo/Application.php:122` (no `mcpProvider`); no provider class in `lib/` | `tests/Unit/Service/LearniqAgentToolsTest.php::testTheAppRegistersScannableServicesAndNoToolProvider` | MET (test added in this PR) |
| Scenario: The derived tools are not shadowed | no `listCourses`/`getCourseDetails` tool in `lib/` (the names survive only in a comment in `lib/Repair/MigrateAppConfigKeys.php`); Course declares `search` and `get` | `McpDialectRegisterTest::testTheSurfaceIsReadOnly`, `LearniqAgentToolsTest::testTheAppRegistersScannableServicesAndNoToolProvider` | MET |
| Scenario: The app registers no tool provider | `Application.php:122` | `LearniqAgentToolsTest::testTheAppRegistersScannableServicesAndNoToolProvider` | MET (test added in this PR) |

## Review table: ai-companion-tools (REMOVED)

| Requirement / scenario | Implementing file:line | Test file::method | Verdict |
|---|---|---|---|
| REQ-001 Provider declares an app id and a hard-coded catalogue (removed) | provider deleted in #1252 | `LearniqAgentToolsTest::testTheAppRegistersScannableServicesAndNoToolProvider` | MET |
| Scenario: No app-owned tool catalogue exists | same | same | MET for "no `IMcpToolProvider`"; the clause "every tool was derived from the schema dialect" is SUPERSEDED by hermiq-ai-tooling REQ-006 (MODIFIED), which permits curated `#[McpTool]` methods via `IMcpScannableServices` |
| REQ-002 Dispatcher routes by tool id (removed) | no learniq dispatcher | none needed | MET; "no Learniq class participates in dispatch" is SUPERSEDED by hermiq-ai-tooling for its four curated tools (OpenRegister dispatches, `LearniqAgentTools` runs) |
| Scenario: Learniq owns no dispatcher | same | none needed | MET (same supersession note) |
| REQ-003 listCourses (removed) | replaced by derived `learniq.course.search` plus the REQ-005 rule | `McpDialectRegisterTest::testDraftsAreStaffOnly` | MET |
| Scenario: The published-only gate survives the migration | Course `authorization.read` | `McpDialectRegisterTest::testDraftsAreStaffOnly`; live r5 check | MET |
| REQ-004 getCourseDetails (removed) | derived `course.get` plus `lesson.search` with `courseId` filter | `McpDialectRegisterTest::testTheSearchFiltersAreTheSpecifiedLists` (Lesson declares `courseId`) | MET |
| Scenario: Course modules are still reachable | same | same | MET |
| REQ-005 Object normalisation (removed) | no learniq normalisation code for the derived surface | none needed | MET |
| Scenario: Learniq owns no object-normalisation code for MCP | same | none needed | MET |

## Totals

11 requirements, 16 scenarios: 27 rows MET (3 requirement rows and 1 scenario row rely on tests added in this PR; 2 removed-requirement rows carry a clause SUPERSEDED by hermiq-ai-tooling), 0 PARTIAL, 0 NOT MET.

## Observations (no action in this PR)

- REQ-006's last sentence ("No `#[McpTool]` attribute and no `IMcpScannableServices` implementation are needed") is no longer true of the codebase: hermiq-ai-tooling modifies REQ-006 and adds both. It is descriptive, not a MUST, and the MODIFIED text in hermiq-ai-tooling wins at archive time. Archive this change before hermiq-ai-tooling so the sync applies the modification last.
- The scenario texts name ids as `learniq.course.search`; the live MCP call used `course_search`. That is OpenRegister's wire name for the same tool, not a learniq mismatch.
- tasks.md acceptance criteria still describe the July scope (6 schemas, 12 tools, an `admin` entry); the Round 5 note explains it and the spec delta matches the code.
