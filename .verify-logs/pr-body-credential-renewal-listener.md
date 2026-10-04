## Scope

`Credential.renewalEnrolmentId` documented a write path ("Written back by OR batch") that did not exist anywhere in this codebase — the four expiry-adjacent notifications (`issuedToLearner`/`expiringSoon`/`expired`/`revoked`) were all real and correctly wired; only the auto-re-enrolment side effect was missing. Adds `CredentialRenewalListener`, mirroring `ExemptionGrantHandler`'s create+link shape: on `Credential.expire`, creates a new `Enrolment` for the same learner/course (`source: credential-renewal`, `mandatory: true`) and writes its id back onto `Credential.renewalEnrolmentId`.

## Evidence

`learniq-defect-triage.md`, entry 5: "`Credential.renewalEnrolmentId` is never written — expiry alerts fire, auto re-enrolment does not exist" — `grep -rn "renewalEnrolmentId" lib/` before this PR finds only the schema declaration and its own doc-comment, no controller/listener/job anywhere.

This also closes the expiry half of the pre-existing `certification` capability spec requirement "Auto-enrol on renewal or content-version change" (`openspec/specs/certification/spec.md`), which already mandated this behaviour outright but was never implemented. The content-version-change half of that same requirement stays a named, open platform gap — `Course` has no content-version concept today, and closing it needs a fan-out across every affected credential-holder, not a single-object transition listener; documented explicitly in the proposal rather than silently left unaddressed.

## M1 rows

Per the triage, row `16.7` ("Renewal and expiry alerts with auto re-enrolment") — the row's only cited gap is this one; this fix **moves the row fully from partial to yes**.

## What was verified (exit codes)

- `php -l` — exit 0 on all 4 touched/new PHP files
- `vendor/bin/phpcs` (targeted) — 0 errors (1 pre-existing inherited "missing @spec" warning on the registrar)
- `vendor/bin/phpunit --filter CredentialRenewalListenerTest` — exit 0 (5/5, 12 assertions)
- Targeted `vendor/bin/phpmd` on the touched files — 0 findings
- `npm run check:json-strict` / `check:register` — exit 0
- `TMPDIR=<outside-repo> COMPOSER_PROCESS_TIMEOUT=0 composer check:strict` (via semaphore) — `ALL CHECKS PASSED`: lint/phpcs/phpmd/psalm/phpstan all 0 errors, full PHPUnit 1079 tests / 4802 assertions / 5 skipped
- `bash hydra/scripts/run-hydra-gates.sh --scope-to-diff --base origin/development` — 2 gates failed: `gate-112 newman-reach` (pre-existing, same as #907/#909/#915); `gate-53 effective-manifest-crossref` — the checker itself throws `ReferenceError: require is not defined in ES module scope` (the same ESM/CommonJS bug in `hydra-gates/scripts/lib/build_effective_manifest.js` already reported on #909, triggered here because this diff also touches a register JSON — not a finding about this PR's content; `check:json-strict`/`check:register` both confirm the register is well-formed)

## Inherited findings

`gate-112` (newman-reach) and `gate-53` (broken checker) are both pre-existing/tooling issues unrelated to this diff, already reported identically on PR #909. Not fixed here per the fleet's inherited-debt policy.

🤖 Generated with [Claude Code](https://claude.com/claude-code)
