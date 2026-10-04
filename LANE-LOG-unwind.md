# Lane log: r2-unwind (learniq)

Lane dir: `/home/rubenlinde/memcap-work/lq-lanes/lq-unwind`. Never staged.
Changes in order: 1 findall-config-filters-sweep, 2 learner-lookup-and-learnerrefs-fixes, 3 privacy-reuse-openregister-register (D20), 4 payments-to-shillinq-migration (D19).

## Started 2026-09-27

## Change 1: findall-config-filters-sweep (DONE)
- Branch `fix/findall-config-filters-sweep` from origin/development 721b28ac; PR https://github.com/ConductionNL/learniq/pull/1047 (head 67dfcc45).
- Setup fix: the lane's vendor/ was nested (vendor/vendor); moved the real one up (outer stub kept at .tmp/vendor-outer).
- OR contract: openregister lib/Service/ObjectService.php @ d611a366 lines 1519-1533 read register/schema from filters only.
- 173 calls fixed (170 literal + 3 variable-built), 102 lib files; 4 duplicate 'filters' keys from the script found and fixed by hand.
- New tests/Unit/FindAllConfigScopeTest.php (346 findings on development, 0 on branch); 73 test files' findAll doubles updated.
- Full PHPUnit: baseline 14 failures (register tests) = branch 14 failures. check:strict exit 1 (phpmd 3 inherited on registrars, test:all 14 inherited). lint 0, format 0, gates diff-scoped exit 3 (gate-46 fixed; gate-3 and gate-61 inherited).
- opsx-verify: clean, recorded in PR body.

## Change 2: learner-lookup-and-learnerrefs-fixes (DONE)
- Branch `fix/learner-lookup-and-learnerrefs`, cut from fix/findall-config-filters-sweep and merged origin/feat/gradeentry-learnerref-stamp (#1020 is still OPEN, not merged as the brief assumed). PR https://github.com/ConductionNL/learniq/pull/1056 (head a930eb30). Land after #1047 and #1020.
- Four LearnerProfile lookups on learnerId fixed (ReportCardComposer via LearnerRefResolver; GradeRollupHandler, ReportCardPublishHandler, LearningPlanSignatureGuard on ncUserId + _rbac false).
- Found: LearningPlanSignatureGuard::fetchRequiredRoles filtered the template on uuid (undeclared) -> every plan activated unsigned. Fixed with `ids`.
- New SubmissionLearnerRefsStamp (create+update) registered in IntegrityListenerRegistrar. No backfill (follow-up).
- Tests on RegisterFaithfulStore, each proven failing on old code. check:strict exit 1 (same inherited phpmd 3 + 14 register test failures, 1499 tests). lint 0, format 0, gates exit 2 (gate-3, gate-61 inherited).
- Incident: a chained sed with `&&` after a failing grep partly ran and rewrote 3 unrelated learnerId lines in GradeRollupHandler; caught by reading the diff, file restored from HEAD and the fix reapplied with an exact python replace.
- Finding for orchestrator: 22 findAll reads filter on id/uuid (undeclared) and return nothing even after #1047 (listed in #1047 body).

## Change 3: privacy-reuse-openregister-register (DONE)
- Branch `feat/privacy-reuse-openregister-register` from origin/development (independent). PR https://github.com/ConductionNL/learniq/pull/1070 (head 4440a529).
- DataSubjectRequest schema + 3 mock seeds removed, register 0.25.0; repair MigrateDataSubjectRequestsToOpenRegister before InitializeSettings (app version bumped); pages -> data-subject-requests/dataSubjectRequest (status lifecycle), retitled Privacy requests; settings list repointed; PrivacyGovernanceDashboard typed dashboard (4 stat + 1 object-table via endpointSource), vue + registry entry deleted; dashboard.json custom 10 -> 9.
- check:strict exit 1 (inherited phpmd 3 + 14 register failures; my notesFor complexity fixed after the run, phpmd on the file 0). check:specs 0, schema-l10n 0 (baseline lowered 2409->2399), lint 0, format 0 after prettier commit, gates exit 1 (gate-53 identical on development).
- Reviewer notes: OR dataSubjectRequest has no authorization block; register is shared; EvidenceSourceProvider left for a follow-up.

## Change 4: payments-to-shillinq-migration (IN PROGRESS at this entry)
- Branch `feat/payments-to-shillinq-migration` from origin/development 48b95aba (register there is 0.25.1). Commit 9f0354c2 local, check:strict + gates running.
- Retired Order/OrderLine/PaymentTransaction (register 0.26.0), Entitlement 0.2.0 (paymentRequestRef, paymentSettledAt), EntitlementPaymentSettledGuard, ShillinqPaymentSettledListener (BootListenerRegistrar filtered on shillinq/PaymentRequest, ObjectUpdatedEvent), ArchiveRetiredPaymentObjects, removed controller/routes/services/guards/listener/panel/pages/GroupPayments/payment-request-ux change; payment connection row repointed; 27 l10n keys pruned; payments spec delta.

## Change 4 update after the coordinator's shillinq #1704 note and the WSL crash (2026-09-27 evening)
- Change 4 was already pushed as PR #1082 (not killed mid-artifacts as the coordinator thought).
- Catch-up merges of development: #1047 (branch 1, fixed 2 new top-level findAll calls the test caught: CurriculumCoverageRollup, SegmentService) pushed 53f9e016; #1070 (branch 3) pushed 5ace75ca; #1082 pushed c49a5c5b (company example set generator dropped orders/payments, corporate.json regenerated, its test rewritten).
- Rework against shillinq contract extracurricular-fee-to-shillinq v1 (subject = FeeItem with app learniq, beneficiary = learner, signal = settledAt edge): ShillinqContributionClient (duck-typed in-process raise), ContributionRaiser, ContributionController POST /api/fee-items/{id}/contributions (action fee-item.raise-contributions), ContributionBeneficiaryResolver, guard + listener rewritten (renamed ShillinqContributionSettledListener), Entitlement.paymentSettledVia, payment connection row now requiredConfig shillinq_administration_id, FeeItemDetail api-call action. Checkpoint commit 52bc5e8d pushed. Remaining: OpenSpec artifacts, strict, gates, PR body, opsx-verify.
- WSL restart wiped /tmp scratchpad (OR source copies, prune list); nothing needed from it any more.
- After the crash: checkpoint 52bc5e8d (rework) and bc744014 (raiser takes fee lookup + administration default to cut controller coupling; OpenSpec artifacts rewritten to contract v1, validate clean) pushed. Diff-scoped phpcs/phpstan/phpmd 0 on the reworked files; gates diff-scoped: only gate-53 (7 findings, identical on development); gates 5, 6, 7, 16, 25, 46, 48, 49, 61, 69 PASS. lint 0, format 0, check:specs 0, schema-l10n 0 (baseline 2358). #1047 and #1070 bodies got catch-up merge notes.
- #1082 finished: check:strict exit 1 (test:all 1668, 12 = development's failing set; phpmd shared-cache artifact, private-cache rerun shows only inherited CaseListenerRegistrar), lint 0, format 0, specs 0, schema-l10n 0, gates exit 1 (gate-53 = development). PR body first line "ready to land", opsx-verify clean recorded. Head bc744014.
- #1056 catch-up: merged origin/fix/findall-config-filters-sweep; IntegrityListenerRegistrar conflict was mechanical (both sides added registrations) -> development's file + SubmissionLearnerRefsStamp block. Full PHPUnit 1673 tests, 12 inherited failures, nothing new. Pushed 04128f9d, body updated ("ready to land after #1047").
## LANE DONE (all four PRs pushed, bodies current)
- 1047 found MERGED at f85374bf (landing lane). 1056 status line updated.
