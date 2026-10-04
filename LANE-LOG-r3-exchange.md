# Lane log r3-exchange (learniq half)

Lane: r3-exchange, part 2 of 2. Never staged. Part 1 = integriq PR #2220 (feat/learniq-exchange-jobs-native).
Clone: /home/rubenlinde/memcap-work/lq-lanes/lq-unwind (previous lane's branch fix/learner-lookup-and-learnerrefs untouched).

## Change 2: data-exchange-to-integriq

- Branch: feat/data-exchange-to-integriq, cut --no-track from origin/development a84b6273.
- Status: proposal written; design next.
- 2026-09-28 wip: pushed b5c02033 (register + example set) and efb23751 (client, gate, listeners, controllers,
  repair step, frontend, l10n, test updates). Remaining: new tests (gate service, builder, listeners, controllers,
  repair step), superseded notes on six changes, e2e specs, check:strict, gates, PR.
- Integriq follow-up pushed af567993 on PR #2220: leerplicht/swv mappings copy the composed file sections
  (passThrough false dropped them).

## 2026-09-28 resume after compaction
- phpcs: 8 errors fixed (named args on exception constructors, inline ifs); 5 class-level @spec warnings added in files already edited.
- phpstan: 2 alreadyNarrowedType in ExchangeGateListener fixed (dropped redundant method_exists after is_a).
- phpmd: ExchangeGateService BooleanArgumentFlag removed (withRecords param gone, HTTP binding already drops records); MigrateDataExchangeToIntegriq complexity 58 -> split into LegacyExchangeTranslator (pure). Diff-scoped phpcs/phpmd/phpstan green on 24 changed lib files.
- Tests: LegacyExchangeTranslatorTest added; controller test asserts records never in the HTTP answer. All exchange tests green.
- Commit 011113b4 pushed.
- Next: check:strict once via with-slot, npm lint/format/l10n checks, hydra gates, PR, opsx-verify.
- check:strict once (exit 1): lint/phpcs/phpmd/phpstan green; psalm 3 UndefinedMethod on ExchangeGateListener (no integriq stub for psalm) fixed via one dynamic answer() call, re-checked per file; PHPUnit 1857 tests, 7 failures = inherited set (subset of .tmp/baseline-fails.txt).
- npm lint/stylelint/format/check:schema-l10n/check:l10n-js/check:specs all 0; test:js-unit 160/162, 2 inherited (files untouched).
- Gates run 1: 5 failed; fixed 4 (stub @spec anchors, x-external-register integriq on 6 job-id props, icon AccountCheckOutline). Run 2: 59/59 applicable ran, gate-53 only (7 findings on development already, +2 requiresApp on status pages, vendored schema stale vs nextcloud-vue).
- Commits 7bd04712 (quality fixes), 88fe3af5 (AttendanceFlagReportGuardTest). Pushed.
- PR ConductionNL/learniq#1157 opened --base development. Integriq PR #2220 body updated (af5679933, learniq PR link).
- opsx-verify (headless, code level): 10/10 tasks, 9/9 added requirements with named tests, contract matches; warning: e2e spec not run (no instance).
- DONE for this lane.
- 2026-09-28 merge development: d121ba69 pushed; TimetableImportController replaces the handler's planninq path; full PHPUnit 0 failures.
- 2026-09-28 second merge of development: ab22be96, full PHPUnit 2111/0 failures.
