# Lane log: r3-segments (learniq, round 3)

Clone: /home/rubenlinde/memcap-work/lq-lanes/lq-school. Logs of heavy runs: `.tmp/*-c<N>.log` in the clone (gitignored).

## 1. company-segment-menu-gating (D26): DONE
- Branch `feat/company-segment-menu-gating` from origin/development a84b6273; commits c7791ac1, d8c306ed; pushed.
- PR https://github.com/ConductionNL/learniq/pull/1125 (base development). Not merged.
- What: `runtime.workspace.chosenSegment` (SegmentService::workspace(), a choice counts only when `setBy` is an existing NC user, so the generated demo rows are not a choice); `{"workspace.chosenSegment": {"notIn": ["corporate"]}}` on report cards/periods/templates, admissions, school advies, parent conferences, BPV cards, exam board cards, Reports cards Attendance flags and Workplace visits; `src/utils/reportCardGates.js` filters Reports cards (CnReportsPage ignores visibleIf).
- Found and fixed (in files already edited): five #1041 segment gates sat on relocated groups and never ran (buildManifest dissolves a relocated group); GroupCompliance gate hid exam board, accessibility and privacy cards from every school. Validator rule 6 now refuses a segment gate on a relocated group (control: names exactly those five on development).
- Verified: phpunit SegmentService+PageController 27 OK; node segmentMenuGates 14, reportCardGates 6, workspaceRuntime 7 OK (control: 7 of 14 fail on development manifests); check:specs 0; merged manifest valid against nextcloud-vue 2.57.1 schema; strict exit 1 inherited only (phpmd CaseListenerRegistrar, 12 register PHPUnit failures whose inputs are untouched); lint 0, format 0; gates exit 3 (112, 113 inherited; 53 crashed on ESM in runner, run by hand as .cjs: 0/0/0). opsx-verify: all clear after ticking the verification boxes.
- Open questions in PR: BSA and subject choices still show for a chosen company (not in the brief's list); mock generator emitting LearniqSettings rows.
- Local env note: node_modules has nextcloud-vue 2.37.0 while the lockfile says 2.57.1 (two connectionRegistry node tests fail on that locally).

## 2. example-set-regulation-rows (D29): DONE
- Branch `feat/example-set-regulation-rows` from origin/development a84b6273; commits 4db9bd2f, 1b55112c; pushed.
- PR https://github.com/ConductionNL/learniq/pull/1137 (base development). Not merged.
- What: contract + ExampleSetDescriptorContractTest: a schema with its own slug `pattern` (Regulation) takes the slug from the object (pattern + unique, no `<id>-` prefix); a set may not re-ship a code the register seeds (AVG). corporate.py 8 Regulation rows (5741 -> 5749), training.py 5 (3831 -> 3836); `regulation` appended to SCHEMAS so no uuid moved; info.version 1.1.0; generators raise when a regulationSlug has no row. AVG omitted (register seeds it, importer matches by uuid -> duplicate). CorporateExampleSetTest reads its certification scopes from the rows.
- Verified: set tests 33 OK; control: dev contract test rejects the rows; generators --check 0 before and after; structural diff only the new bucket; check:json-strict/register/schema-l10n/l10n-js 0; strict exit 1 inherited only (same 12 register tests, phpmd CaseListenerRegistrar); lint 0, format 0; gates exit 2 (112, 113 inherited; 68 wiring skip). opsx-verify clear.
- Open: publish the register's AVG row (r3-access owns learniq_register.json); both corporate+training loaded duplicates VCA/NIS2.
- Note: a rate-limit pause hit while strict ran; the run completed, push and PR happened after the reset.

## 3. example-set-removal-in-wizard: DONE
- Branch `feat/example-set-removal-in-wizard` from origin/development 0171a896; commits 52eec1aa, 1e9b9939, d5d061a9; pushed.
- PR https://github.com/ConductionNL/learniq/pull/1138 (base development). Not merged.
- What: SeedProfileService::importAppId()/remove() duck-types ConfigurationService::softDeleteAppImports (openregister #4080, read-only); SetupController action `remove-example-set` (always reported done so CnSetupWizard never auto-runs it; occ fallback text; errors name one purge command per unfinished job); segment answer 403 outside admin/administration-managers (r3-access #1124 leaves LearniqSettings.authorization empty, so enforced in the controller); manifest step + en/nl strings; contract + docs.
- Verified: SetupController+SeedProfileService tests 34 OK (DemoDataService too); phpcs/phpstan/psalm/phpmd 0 on touched lib (flattened remove() after phpmd class complexity 50); setupSteps node 6 OK; check:specs, schema-l10n, l10n-js 0; strict exit 1 inherited only (same 12 register tests, phpmd CaseListenerRegistrar); lint 0, format 0; gates exit 3 (112, 113 inherited; 53 fail + 22/68/104/107 wiring skips = runner ESM fault; run by hand as .cjs: 53 0/0/0, 68 two pre-existing warnings, 104 0, 107 0). opsx-verify clear.
- Open: one remove button per loaded set; r3-access to add LearniqSettings.authorization.

## CI read (once, 2026-09-28)
- #1125: fails = phpmd (CaseListenerRegistrar), PHPUnit (failure set identical to the local 12 inherited; 10 risky are course-sharing tests), Hydra Gates (full-tree scope: gates 3, 25, 49, 55, 62, identical counts on r3-access #1124), Quality Report (aggregate). No NEW red.
- #1137: same phpmd, PHPUnit (identical 12), Hydra Gates (same five). No NEW red.
- #1138: all checks still pending at read time; not polled (rules). Its local strict/gates are in the PR.
- Merge check: each branch merges clean into origin/development, and pairwise with each other.
- LANE DONE. Nothing left undone inside the brief; open questions are in each PR body.
