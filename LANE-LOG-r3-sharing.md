# Lane log: r3-sharing (round 3, clones ncv + lq-privacy)

Untracked, never staged. Sources: LANE-RULES.md, -R2, -R3; D22, D27; lq-privacy/LANE-LOG.md (r2 sharing lane); or-primitives/LANE-LOG-r2.md (openregister #4079, landed 84352bae).

Resume: read this file, `git status` in both clones, continue from the first change not marked DONE. Cut branches with `git checkout --no-track -b <branch> origin/development`. Heavy commands through `with-slot.sh`. Hydra gates only AFTER committing.

## Starting state (2026-09-27 ~20:00)
- ncv: idle on feat/guardian-portal-surface-pattern (previous lane, finished; not modified). origin/development c8aa85863. package.json version 2.0.5 (never bump). Tests run on JEST here, not vitest.
- lq-privacy: idle on feat/assessment-portal-endpoints (r2-portal-learniq, finished; not modified). origin/development a84b6273.
- No process had its cwd in either clone (checked /proc/*/cwd).
- learniq #1043 (store), #1029, #1026, #1080 (teacherNote) all MERGED. openregister #4079 merged: GenericStoreService::publish(descriptor, payload), StoreDescriptor publishFields/publishGroups + isPublishable(), OCA\OpenRegister\AppHost\Store\StoreActionAuthorizer::canPublish(descriptor, user). Its design.md "How learniq adopts this" is the plan for change 2a.
- CnStorePage today: Install shown only when getCurrentUser().isAdmin; no Publish action at all.
- learniq matrix: actions.seed.json course-package.import ["admin"], course-package.share ["admin"]; GenericInitializeActions seeds only an EMPTY matrix, so new defaults need a one-shot migration for existing installs. Groups: instructors, team-leads, coordinators (DashboardRoleService).

## Plan
1. ncv `store-page-action-visibility`: CnStorePage props canInstall / canPublish (null = admin-only, today's behaviour), publishRoute (Publish button only when set, so no other app changes). Jest tests, docs.
2a. learniq `store-publish-through-plane` (from origin/development): CourseStorePublisher -> GenericStoreService::publish, descriptor publishFields + publishGroups from matrix course-package.share, duck-typed (property_exists / method_exists), drop CourseStoreUrlGuard, gate 62 clears.
2b. learniq `store-rights-for-teachers` (stacked on 2a): course-store.install action for teachers, course-package.share default team-leads, initial state -> Store page config canInstall/canPublish/publishRoute, admin settings section for the registry connection.
2c. learniq `teacher-notes-protection` (from origin/development): LessonTeacherNote schema with staff-only authorization, composer and importer write there, migration moves existing teacherNote blocks, Lesson enum drops teacherNote.

## Change 1 (ncv): store-page-action-visibility: DONE
- Branch feat/store-page-action-visibility (--no-track from origin/development c8aa85863). Commits 83f672807, 3bf9bec29. Pushed.
- PR https://github.com/ConductionNL/nextcloud-vue/pull/1268 (not merged; no version bump).
- CnStorePage: canInstall / canPublish (Boolean, default null = admin only), publishRoute (string route name or location; no route = no Publish button). computed canInstall -> showInstall. Header Publish NcButton -> this.$router.push. docs/components/cn-store-page.md new; _generated/CnStorePage.md regenerated with vue-docgen-cli 4.79.0 installed in the scratchpad (docusaurus deps absent; control run reproduced every tracked file). "Publish" already "Publiceren" in l10n/nl.json.
- Verified: openspec validate --strict valid; jest file 31/31 (12 new); mutation (defaults -> false) 3 fail; eslint 0; npm run lint 0 (1 inherited warning CnMapWidget:125); npm test 804 suites / 9938 tests 0; build 0; check-docs 0; docgen idempotent; gates exit 1 = gate-84 only (inherited .npmrc/engines), gate-16 fixed in 2nd commit.
- opsx-verify: pass, recorded in PR body.
- Tests are JEST in this repo (brief said vitest).

## Change 2a (learniq): store-publish-through-plane: DONE
- Branch feat/store-publish-through-plane (--no-track from origin/development a84b6273). Commit cc73f0b9. Pushed.
- PR https://github.com/ConductionNL/learniq/pull/1128
- CourseStorePublisher -> GenericStoreService::publish; CourseStoreDescriptor PUBLISH_FIELDS + publishGroups = getAllowedGroups('course-package.share') (needs ActionAuthService), args spread only when supportsPublish(); authorizer resolved lazily from container, fail closed; StoreController 501 publish_not_supported / 403 forbidden before the gate; status map + rate_limited 429, invalid 502, not_publishable 500. CourseStoreUrlGuard + test + SecurityService stub removed. Stubs follow #4079 (+ StoreActionAuthorizer stub, GenericActionAuthService getAllowedGroups/can). ExportRequestView copy + nl. Docs admin 03.
- method_exists probes go through helpers with a string param (phpstan narrows literal probes to "always true" and the config forbids ignores).
- Literal outcome consts for the 4 new plane outcomes (older OR lacks those consts; const expr would be fatal).
- Verified: openspec valid; store tests 40 OK; phpcs/phpstan/phpmd touched 0; check:strict 1 = phpmd CaseListenerRegistrar + 12 PHPUnit register tests (known red set; diff doesn't touch learniq_register.json); lint 0; format 0; gates 2 = gate-112, gate-113 (payments spec) inherited; gate 62 full tree: development 1 (CourseStorePublisher), branch 0.
- composer wrapper uses $HOME/.local/share/composer.phar: with isolated HOME, symlink it into .tmp/home/.local/share/.
- opsx-verify: pass (in PR body).

## Change 2b (learniq): store-rights-for-teachers: DONE (stacked on 2a)
- Branch feat/store-rights-for-teachers cut from feat/store-publish-through-plane (cc73f0b9).
- Artifacts: proposal, spec (5 reqs), design, migration, test-plan, tasks. openspec valid.
- Tasks 1-3 DONE, committed + pushed (resumed after an account limit on 09-28): seed course-store.install [admin, instructors, team-leads], course-package.share [admin, team-leads]; StoreController ACTION_INSTALL = course-store.install; repair step ApplyStoreRightsDefaults (post-migration after InitializeActions, marker store_rights_defaults_applied); StoreAccessService::forUser {install, publish}; PageController initial state storeAccess (lazy, degrades to none). 40 related tests OK.
- Next: task 4 (src/utils/storeAccess.js applyStoreAccess, main.js, manifest publishRoute, export menu team-lead, ExportRequestView publish button), task 5 (registry settings controller + section), task 6 (copy, docs), then strict, gates, PR, verify.
- Tasks 4-6 DONE: src/utils/storeAccess.js applyStoreAccess (main.js after buildManifest), Store config publishRoute CoursePackageExport, export menu admits team-lead, ExportRequestView publish button gated on storeAccess.publish; StoreRegistrySettingsController (AuthorizedAdminSetting, GET/PUT /api/admin/store-registry, token sensitive + write-only, IL10N refusals) + StoreRegistrySettingsSection.vue in AdminRoot; 16 nl keys; admin + teacher docs.
- PageController coupling hit 13 -> StoreAccessService::forCurrentUser() reads the session itself.
- Gate 110: a new repair step needs a <version> bump in the same PR -> 0.3.6-unstable.20260928100000 (ec18a785).
- Commits e6374e10, fa799089, 8b2d08f7, ec18a785, 292cd55b. PR https://github.com/ConductionNL/learniq/pull/1140
- Verified: openspec valid; touched PHPUnit classes OK; node storeAccess 6/6; node all = same 4 failures as development; phpcs/phpstan/phpmd touched 0; check:strict 1 = identical failure set to 2a (compared by name); lint/format/check:specs/schema-l10n/l10n-js 0; gates 3 = 53 (box ESM env), 112, 113 inherited.
- No nextcloud-vue pin (#1268 unreleased); keys written now, admin default holds on the page until a release.
- opsx-verify: pass after the D3 wording fix (in PR body).

## Change 2c (learniq): teacher-notes-protection: IN PROGRESS
- Branch feat/teacher-notes-protection (--no-track from origin/development 0171a896). Artifacts committed + pushed (proposal, spec 5 reqs, design, migration, test-plan, tasks); openspec valid.
- Plan: LessonTeacherNote schema (staff-only authorization, searchable false), Lesson enum drops teacherNote (Lesson 0.5.0), TeacherNoteSplitter (pure), importer writes notes after final blocks, repair MoveTeacherNotesOutOfLessons (post-migration after InitializeSettings; create-then-strip, idempotent by lessonId+blockId), composer split/merge/diff in lessonBlocks.js, <version> bump, mock rows via generate_mock_register.py --keep.
- Landing note added to #1140: if release PR #1136 (0.3.7-unstable.20260928044041) lands first, keep <version> above it.

## Change 2c (learniq): teacher-notes-protection: DONE
- Commits d074d207 (artifacts), 8f769301 (register), 2e4573a5 (splitter + importer), d5a94d99 (repair + version), 1d67bb4e (composer + docs), 740d5359 (gate 108 mock fix). PR https://github.com/ConductionNL/learniq/pull/1146
- LessonTeacherNote (staff read: instructors, team-leads, coordinators, hr, compliance-officers, administration-managers; write = lesson writers; searchable false; seed + 3 mock rows spliced in; generator rewrote 23k lines when run in place, so never write the mock file with it). Lesson 0.5.0 enum without teacherNote; info.version 0.29.2 (0.29.0/0.29.1 taken by #1124/#1126/#1127/#1129). App <version> 0.3.6-unstable.20260928110000.
- Verified: learner-side register test (opis validation of the block type); splitter 5, importer 8, repair 5, node 8; strict 1 = same failure set by name; node all = same 4 as development; lint/format/specs/schema-l10n/l10n-js 0; gates: 108 fixed (Entitlement orderLineId in mock, file I edit), remaining 53/112/113 inherited.
- opsx-verify: pass (in PR body).

## CI read (once per PR, lane end, 28 Sep)
- nextcloud-vue #1268: 28 pass, 12 skipping, 0 fail.
- learniq #1128, #1140: phpmd, PHPUnit (12 known), Hydra Gates (3/25/49/55 baseline counts), Quality Report. Gate 62 gone. 10 risky StoreControllerTest cases (no @uses for CourseStoreDescriptor/SharingBlockedException) -> fixed d89e7e91 on #1128, merged into #1140 (1e669df7). No coverage driver locally; next CI run proves it.
- learniq #1146: PHPUnit 12 = local set, 10 risky not mine; gates baseline + gate 62 (development since #1043; #1128 fixes it).
- PR bodies of 1128, 1140, 1146 carry the CI read.

## Lane status: DONE. PRs: nextcloud-vue #1268; learniq #1128, #1140 (stacked on #1128), #1146. None merged.
