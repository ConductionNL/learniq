# Lane r5-security (round 5, 2026-09-28)

## 1. fix-cross-tenant-idor-planid-lookups: DONE
- branch fix/cross-tenant-idor-planid-lookups, PR https://github.com/ConductionNL/learniq/pull/1247 (e67dfad7)
- tests first: 5 red on development (every named lookup), green after fix; same-tenant controls
- check:strict exit 0 (2214 tests, 0 failures); npm lint 0; prettier tracked 0; test:l10n missing; gates exit 5 all inherited (3, 25, 49 ComplianceRollup, 55, 60); gate-7 PASS
- opsx-verify: PASS (11/11 tasks, 3/3 reqs, 5/5 scenarios with named tests)

## 2. controller-test-coverage-security-critical: DONE
- branch test/controller-coverage-security-critical, PR https://github.com/ConductionNL/learniq/pull/1274 (57a48ea9)
- found + fixed: action-matrix PUT without matrix wiped every grant (test red first)
- HeaderUtils test stub so DataDownloadResponse is testable in pure-unit mode
- check:strict exit 0 (2215 tests); lint 0; prettier tracked 0; gates exit 5 inherited only
- opsx-verify: PASS (15/15 tasks; 5.1 = existing #564)

## 3. wire-l10n-parity-ci-gate: DONE
- branch ci/wire-l10n-parity-gate, PR https://github.com/ConductionNL/learniq/pull/1329 (0a1f8020)
- 3.1 decision: ratchet (nl strict, other locales may not lose translations, down path fails); baseline l10n/.l10n-parity-baseline.json
- mutation-tested both directions; check:strict 0; lint/prettier/l10n-js/schema-l10n 0; gates 5 inherited
- merged origin/development: ratchet caught a reworded key without nl, baseline refreshed
- opsx-verify: PASS (8/8)

## CI read (once, lane end)
- #1247: only Hydra Gates + Quality Report red = the 5 inherited gates (3, 25, 49 ComplianceRollup, 55, 60)
- #1274: same 5 + PHPUnit (real OR AuditTrailMapper arg order); fixed in 07f72cd7, stub aligned, body edited
- #1329: all pending at read time; not polled
LANE DONE
