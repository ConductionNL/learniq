# Lane log: data set lane he (learniq round 2, batch 2)

Clone: /home/rubenlinde/memcap-work/lq-lanes/lq-contracts (belongs to this lane until its final message).
Brief: /home/rubenlinde/memcap-work/lq-lanes/DATASET-LANE-BRIEF.md, segment `he`.

## Setup (2026-09-27)
- The clone held an untracked LANE-LOG.md from the earlier lq-contracts lane that blocked the checkout
  (development tracks a LANE-LOG.md). Moved it, unchanged, to `LANE-LOG-lq-contracts.md` (untracked) in
  this clone. Nothing deleted.
- The finished branch feat/entree-surfconext-sso-contract is untouched.
- Branch: `feat/segment-example-datasets-he`, cut with `--no-track` from
  `origin/feat/segment-example-datasets-po` (05da1e2). Stacked on learniq #1031 (on #1028 on #1022).

## Change: segment-example-datasets-he
- status: in progress
- 27767db committed locally (not pushed yet): generator, he.json (5842 objects), HigherEducationExampleSetTest,
  l10n key (en/nl + built js), openspec change (proposal, spec, design, tasks; discovery/contract/migration/test-plan
  skipped as optional and not applicable). `openspec validate --strict` exit 0.
- Diff-scoped checks: contract + content tests OK (14 tests, --no-coverage exit 0), check:register 0,
  check:schema-l10n 0 (10 below baseline, inherited, baseline not touched), check:l10n-js 0, check:json-strict 0,
  check:manifest 0, gate 108 on he.json exit 0 (5842 objects). Controls: a wrong objectCount, a shifted final
  grade and a wrong BSA credit count each fail the tests.
- Next: check:strict once (semaphore), npm lint/format/test:l10n, hydra gates, push, PR, opsx-verify.
- Pre-push (once): check:strict exit 1 (lint/phpcs/psalm/phpstan pass; phpmd 3 inherited in lib/AppInfo/Registrar;
  PHPUnit 1507 tests, 12 failures = the 12 #1031 records, new tests pass). npm lint 0, format 0, test:js-unit 1
  (3 inherited), no test:l10n script. Hydra gates --base origin/development exit 6 (3, 25, 49, 53, 55, 112, all
  inherited; 101/108/109 pass). Gate 101 and 109 standalone 0.
- 80ef19d pushed (ls-remote confirms). PR https://github.com/ConductionNL/learniq/pull/1055 (base development,
  stacked on #1031). Body kept at .tmp/pr-body-he.md in this clone.
- Next: opsx-verify, then read CI once at the end.
- opsx-verify (headless): pass 1 no CRITICAL, 3 WARNINGs (test strength: one-directional checks). Fixed in 7d73422
  (pushed, ls-remote confirms): both-direction checks for final grades/enrolments/entries, exactly one advice per
  student, every submission summarised, every main-sitting item has a statistic. Control run fails as expected.
  Pass 2: no CRITICAL/WARNING; 1 SUGGESTION (long build()) left. PR body updated with the verdict.
- status: done, pending one CI read.
