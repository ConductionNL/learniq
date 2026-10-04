# LANE-LOG r5-integrations (learniq, clone lq-unwind)

## 1. openconnector-flow-migration: SUPERSEDED (done)
- Branch docs/openconnector-flow-migration-superseded, PR #1241. Both call sites deleted by #1157 (D7/D25). Note in tasks.md, nothing ticked. validate 0.

## 2. leaf-integrations: BUILT, narrowed by D1 (done)
- Branch feat/leaf-integrations, PR #1243 (7a0fac8b). 6 schemas linkedTypes, 7 widgets, e2e written (not run), docs. Polls + Cohort calendar/forms dropped per D1.
- strict: only failure a version pin test, fixed to a floor (suite 0 failures). lint 0, format 0, gates 8 all inherited. verify: 4 manual boxes open.

## 3. scholiq-mcp-adoption: BUILT (done)
- Branch feat/mcp-adoption, PR #1252 (42f32cc8). 5 schemas (session OFF, learner ids), read rules staff + lifecycle match, provider deleted, McpDialectRegisterTest red-then-green, docs/Technical/agent-tools.md. wip/mcp-adoption had nothing.
- strict exit 0 (2197 tests, 0 failures), lint 0, format 0, gates 8 inherited.

## 4. cmi5-xapi-lrs-ingest: BUILT (done)
- Branch feat/cmi5-xapi-lrs-ingest (67312609). Token service, key admin, launch+fetch, LRS, XapiStatement authz (was none), SCORM 1.2 to LRS, docs. strict 0 (2219), lint/format/specs 0, js 1 inherited, gates: gate-82 fixed, rest inherited. Task 3.4 open.

## 5. content-lti-launch-through-integriq: BUILT (done, live run open)
- Branch feat/content-lti-launch-through-integriq. Event-based launch, form util, poll job line item, lti row reportedOnly + LtiSettingsSection. strict 0 (2205), lint/format/specs 0, js 1 inherited, gates 8 inherited.

## 6. hermiq-ai-tooling: BUILT IN PART, stacked on #1252 (done)
- Branch feat/hermiq-ai-tooling (f862225d). 3 write tools + minimised read; issueCredential + two-phase tokens + agent id superseded (reasons in tasks.md). strict: psalm attribute fixed, tests 2203/0, lint/format/specs 0, gates 8 inherited.
## LANE DONE
- CI read once: 1241 and 1243 MERGED; Hydra Gates red on every PR incl. docs-only 1241 = inherited set (gate-3/25/49/55/60). Catch-up merges of development into 1252, 1277, 1347 (register/l10n conflicts rebuilt), 1365 merged updated mcp branch; full PHPUnit 0 failures each; pushed; bodies updated.
