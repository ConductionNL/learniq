## Scope

`Cohort.ncGroupId` is a documented field nothing ever populated — the only other write site, `RolloverExecutionService`, computes a human-readable name string, never a real Nextcloud group, and `CohortMembershipGuard`'s own class docblock defers provisioning to "a separate event listener or manual admin action." Adds `CohortGroupProvisioningHandler`, mirroring `CohortTalkMembershipHandler`'s shape: on Cohort `activate`, provisions a real NC group via `IGroupManager`, adds `teacherIds`/`learnerIds` as members, writes the group id back; on Enrolment `activate`/`withdraw`, keeps that group's membership in step once provisioned.

## Evidence

`learniq-defect-triage.md`, entry 4: "`Cohort.ncGroupId` is a field nothing ever provisions" — `grep -rn "ncGroupId" lib/` finds exactly one write site (a computed name string, not a group id) and zero `OCP\IGroupManager` calls anywhere in the app before this PR.

## M1 rows

Per the triage, row `1.12` ("Groups mapped to platform groups for files, chat and calendar") cites a second, independent gap (no calendar) this fix does not touch, so it stays `partial` even after this fix, but the ncGroupId-provisioning half of the gap is now closed.

## What was verified (exit codes)

- `php -l` — exit 0 on all 3 touched/new PHP files
- `vendor/bin/phpcs` (targeted, both files) — exit 0, 0 errors (1 pre-existing "missing @spec" warning on the registrar, unrelated to this diff's content)
- `vendor/bin/phpunit --filter CohortGroupProvisioningHandlerTest` — exit 0 (9/9, 18 assertions)
- `TMPDIR=<outside-repo> COMPOSER_PROCESS_TIMEOUT=0 composer check:strict` (via semaphore) — lint: 0 errors; phpcs: 0 errors; **phpmd: exit 1 — see "phpmd anomaly" below**; psalm: 0 errors (647 pre-existing info-level findings); phpstan: 0 errors; full PHPUnit: 1083 tests / 4808 assertions / 5 skipped, all pass. Overall `check:strict` exit code: **1**, entirely attributable to the phpmd anomaly documented below — every other stage is clean.
- `bash hydra/scripts/run-hydra-gates.sh --scope-to-diff --base origin/development` — 1 gate failed: `gate-112 newman-reach` (pre-existing, identical to PR #907/#909, unrelated to this diff).

## phpmd anomaly (thoroughly investigated, not a real complexity regression)

`composer phpmd` (which runs `vendor/bin/phpmd lib text phpmd.xml` — a single top-level directory argument) reports:
```
lib/Listener/CohortGroupProvisioningHandler.php:225  CyclomaticComplexity  ...has a Cyclomatic Complexity of 10... threshold is 10.
lib/Listener/CohortGroupProvisioningHandler.php:225  NPathComplexity       ...has an NPath complexity of 288... threshold is 200.
```
Line 225 is `syncMembership()`, which after refactoring is three simple early-return guard clauses (manual count: cyclomatic complexity ≈5, nowhere near 10, and NPath in the single digits, nowhere near 288). I reproduced this **before and after** a substantial rewrite of the method (extracting `resolveProvisionedGroup()`/`applyMembership()`/`createOrGetGroup()` to remove two `phpmd`-flagged else-expressions and cut branching) and got the **exact same numbers both times** — inconsistent with the tool re-analysing genuinely different code.

Full bisection (all runs from the repo root, same `phpmd.xml` ruleset):
- `vendor/bin/phpmd lib/Listener/CohortGroupProvisioningHandler.php text phpmd.xml` — clean
- `vendor/bin/phpmd lib/Listener,lib/Service text phpmd.xml` — clean
- `vendor/bin/phpmd <all 17 of lib's top-level subdirectories, comma-separated> text phpmd.xml` — clean (this is the *same 222 files* `lib` contains)
- `vendor/bin/phpmd lib text phpmd.xml` (single directory argument, letting phpmd recurse itself) — **reproduces every time**, deterministically, with the identical two findings and no others anywhere in the tree

Passing phpmd the exact same file set as an explicit list of subdirectories is clean; passing it the one parent directory and letting it recurse is not. This is a tool-scale artifact triggered by phpmd's own recursive directory walk at this codebase's size (222 files), not a property of the code at line 225 — every smaller-scope invocation (including ones covering the same complete file set) disagrees with it. I did not suppress or work around this with a `@SuppressWarnings` annotation because I could not construct a matching root cause to justify one truthfully; instead this is reported in full so it does not silently repeat on a future PR that happens to land near this same spot in `lib`'s directory order.

## Inherited findings

Psalm: 647 pre-existing info-level findings, unrelated to this diff. `gate-112 newman-reach`: pre-existing, unrelated. The phpmd anomaly above is reported in detail rather than silently worked around.

🤖 Generated with [Claude Code](https://claude.com/claude-code)
