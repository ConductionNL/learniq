# Lane log: lq-defects

Lane dir: `/home/rubenlinde/memcap-work/lq-lanes/lq-defects` (clone of learniq,
source checkout `apps-extra/scholiq`, app id `learniq`).

## Incident: shared scratchpad log-filename collision (2026-09-25 ~20:41)

Heavy-command logs were initially written to the orchestrator-shared session
scratchpad (`/tmp/claude-.../scratchpad/check-strict-1.log`,
`.../hydra-gates-1.log`). That directory is shared by every lane in this
orchestration run (same session UUID), and another lane (`or-primitives`)
independently redirected its own `composer check:strict` to the identical
filename `check-strict-1.log`, truncating and overwriting it with its own
output around 20:41. Caught by re-reading the file and noticing OpenRegister
class names (`RelationHandlerCircuitBreakerTest`, `StreamingBulkUpsert...`)
that do not exist in learniq.

Impact assessed: my change 1 (`registry-component-fix`) `composer check:strict`
run had already completed and been read in full (1074 tests, 4790 assertions,
5 skipped, `ALL CHECKS PASSED`) at ~20:36, five minutes before the other lane's
write at 20:41 — so that specific result is trusted. `hydra-gates-1.log` was
not corrupted (still showed `App dir: .../lq-defects` when re-checked) but had
been aborted early by my own `kill` (see below), not by contamination.

**Fix applied:** every heavy-command log from this point forward is written
under the lane's own directory (`lq-defects/.verify-logs/`, untracked, not
`git add`ed), never the shared scratchpad. Also switched the hydra-gates
invocation from full-repo scope (which was going to take a very long time and
report the whole fleet's inherited debt, not this change's) to
`--scope-to-diff --base origin/development`, matching the top-level CLAUDE.md
verification-order policy.

---

## Change 1: registry-component-fix

- Branch: `fix/registry-component-fix` (cut from `origin/development` at `5dfc675`)
- Scope: register 8 missing `@conduction/nextcloud-vue` components
  (`CnDataMatrix`, `CnWizardDialog`, `CnRichSubmitDialog`, `CnExportWizard`,
  `CnSignatureCapture`, `CnTimelineView`, `CnStructuredDocReview`,
  `CnRelationshipGraph`) in `src/registry.js` as `kind: "page"` entries, so the
  14 manifest pages naming them mount instead of rendering empty
  (learniq-defect-triage.md entry 1). Added a `node --test` regression test
  (this repo has no vitest) that diffs every `type: "custom"` manifest page's
  `component` against the registry keys.
- Files: `src/registry.js`, `tests/unit-js/registryComponentCoverage.test.mjs`,
  `openspec/changes/registry-component-fix/*`.
- Verification (see PR body for full detail): `node --test
  tests/unit-js/registryComponentCoverage.test.mjs` exit 0 (3/3);
  `npx eslint src/registry.js tests/unit-js/registryComponentCoverage.test.mjs`
  exit 0; `npm run check:manifest` exit 0; `npm run test:js-unit` (full suite)
  exit 0 with 3 pre-existing/inherited failures unrelated to this change
  (confirmed via `git stash` against baseline, which already had those same 3
  failures); `npm run build` (via semaphore) exit 0; `npm run lint` exit 0 (19
  pre-existing warnings, 0 errors, none in touched files); `npm run format`
  exit 0; `composer check:strict` (via semaphore, `TMPDIR=$PWD/.tmp
  COMPOSER_PROCESS_TIMEOUT=0`) — `ALL CHECKS PASSED`, 1074 tests / 4790
  assertions / 5 skipped, phpcs/phpmd/psalm/phpstan all 0 errors (641
  pre-existing psalm info-level findings, inherited); hydra gates diff-scoped
  against `origin/development` — see PR body for the exact gate list once that
  rerun finishes.
- PR: https://github.com/ConductionNL/learniq/pull/907
- hydra gates (diff-scoped, `--base origin/development`) final result: 1 gate
  failed — `gate-112 newman-reach` (0 of 90 committed Postman requests run),
  pre-existing/whole-app, unrelated to this diff; `gate-68 duplicate-index-pages`
  did not run (broken checker script, exit 1, unrelated). All other gates in
  scope PASS or correctly NOT APPLICABLE (diff touches only `src/registry.js`
  and one new test file).
- Status: DONE. Machine crashed 2026-09-25 ~21:00 mid-writeup (background
  monitors died); resumed 2026-09-26, all verification exit codes had already
  been captured in this log so nothing was re-run, only the PR was (re)created
  since the crash happened before `gh pr create`.
- `opsx-verify`: posted as PR comment https://github.com/ConductionNL/learniq/pull/907#issuecomment-5844179809 — no CRITICAL/WARNING issues, completeness 10/10 real tasks (5 N/A quality-checklist lines justified). Not archived (LANE-RULES doesn't ask for it; archiving is a separate follow-up).
- **CHANGE 1 COMPLETE.**

## Change 2: session-roster-notifications

- Branch: `fix/session-roster-notifications` (cut from `origin/development` at
  `86612d1`), pushed. Commit `ff0a348`.
- Scope: added `Session.x-openregister-notifications.rosterChanged` (transition
  trigger on cancel/substitute-teacher/substitute-teacher-in-progress,
  `kind:field` recipients `affectedLearnerIds`/`affectedParentIds`, already
  materialised by the unchanged `SessionChangeNoticeHandler`). Register
  `info.version` bumped 0.21.0 -> 0.22.0. Added
  `tests/Unit/Settings/SessionRosterNotificationRegisterTest.php` (5 tests, 12
  assertions).
- Files: `lib/Settings/learniq_register.json`,
  `tests/Unit/Settings/SessionRosterNotificationRegisterTest.php`,
  `openspec/changes/session-roster-notifications/*`. NOTE: this change dir has
  no `.openspec.yaml` (hand-authored rather than via `openspec new change`);
  `openspec validate session-roster-notifications` passes regardless.
- Verification so far: `python3 -c json.load(...)` (register still valid JSON)
  OK; `npm run check:json-strict` exit 0; `npm run check:register` exit 0;
  `php -l` on the new test file exit 0; `vendor/bin/phpcs` on the new test
  file: 10 findings, ALL matching the "named parameters" sniff that fires
  identically on every existing `*RegisterTest.php` (confirmed by running the
  same phpcs command against `ReportCardComposerRegisterTest.php`) — AND
  `phpcs.xml`'s only configured `<file>` is `lib`, so `composer phpcs` (part of
  `check:strict`) never lints `tests/` at all; 0 NEW findings on lines this PR
  touches. `vendor/bin/phpunit --filter SessionRosterNotificationRegisterTest`
  exit 0 (5/5, 12 assertions). `npm run lint` exit 0 (19 pre-existing warnings,
  0 errors). `npm run format` exit 0.
- `composer check:strict` and diff-scoped hydra gates launched in background
  (`.verify-logs/check-strict-session-roster-notifications.log`,
  `.verify-logs/hydra-gates-session-roster-notifications.log`), via `nohup
  ... & disown` this time so they survive a shell/session reset. Results to be
  read before opening the PR.
- PR: https://github.com/ConductionNL/learniq/pull/909
- `composer check:strict`: `ALL CHECKS PASSED` (1079 tests / 4802 assertions / 5
  skipped). hydra gates diff-scoped: 2 failed — `gate-112 newman-reach`
  (pre-existing, same as #907) and `gate-53 effective-manifest-crossref`
  (checker itself throws `ReferenceError: require is not defined in ES module
  scope` — an ESM/CommonJS bug in the hydra-gates tooling, not a finding about
  the register JSON; `check:json-strict`/`check:register`/plain `json.load`
  all confirm the register is well-formed). `gate-68 duplicate-index-pages`
  broken checker, same as #907.
- `opsx-verify`: posted as PR comment
  https://github.com/ConductionNL/learniq/pull/909#issuecomment-5844217348 —
  no CRITICAL/WARNING issues.
- **CHANGE 2 COMPLETE.**

## Change 3: cohort-group-provisioning

- Branch: `fix/cohort-group-provisioning` (cut from `origin/development`),
  pushed. Commit `185a368`.
- Scope: new `CohortGroupProvisioningHandler` (mirrors
  `CohortTalkMembershipHandler`'s shape) — provisions a real NC group via
  `IGroupManager` on Cohort `activate`, adds `teacherIds`/`learnerIds` as
  members, writes the group id back onto `Cohort.ncGroupId`; on Enrolment
  `activate`/`withdraw`, syncs that group's membership once provisioned.
  Registered in `CollaborationListenerRegistrar` right after
  `CohortTalkMembershipHandler`. 9 unit tests, 18 assertions, `createMock()`
  doubles only (no `addMethods`).
- Files: `lib/Listener/CohortGroupProvisioningHandler.php`,
  `lib/AppInfo/Registrar/CollaborationListenerRegistrar.php`,
  `tests/Unit/Listener/CohortGroupProvisioningHandlerTest.php`,
  `openspec/changes/cohort-group-provisioning/*`.
- Verification so far: `php -l` exit 0 on all 3 touched/new PHP files;
  `vendor/bin/phpcs` on the new listener and the registrar: 0 errors after
  two fix rounds (inline ternaries -> if/else, named params on own method
  calls — this repo's phpcs.xml requires named args even on private-method
  calls); the registrar's 1 remaining warning ("missing @spec PHPDoc tag") is
  pre-existing on that class, not introduced by this diff.
  `vendor/bin/phpunit --filter CohortGroupProvisioningHandlerTest` exit 0
  (9/9, 18 assertions). `vendor/bin/phpstan analyse` run narrowly against just
  the 3 touched files reports 1 `class.notFound` on
  `OrEntityFactory::make()` — confirmed as a pre-existing artifact of running
  phpstan against a narrow file subset outside its configured autoload scope
  (identical false positive reproduced against the already-merged
  `ExemptionGrantHandlerTest.php` the same way); the full `composer phpstan`
  inside `check:strict` is the authoritative signal.
- `composer check:strict` and diff-scoped hydra gates launched in background
  (`.verify-logs/check-strict-cohort-group-provisioning.log`,
  `.verify-logs/hydra-gates-cohort-group-provisioning.log`), `nohup ... &
  disown`. Results to be read before opening the PR.
- PR: https://github.com/ConductionNL/learniq/pull/915
- Follow-up commit `4863292` fixed 4 real phpmd findings from a targeted
  scan (2 else-expressions, cyclomatic/NPath complexity on syncMembership,
  coupling on the registrar — the last suppressed with a documented
  `@SuppressWarnings`, the rest fixed by extracting 3 helper methods).
- **phpmd anomaly discovered and thoroughly bisected**: `composer phpmd`
  (`vendor/bin/phpmd lib text phpmd.xml`, single top-level dir argument, lets
  phpmd recurse itself) STILL reports CyclomaticComplexity=10/
  NPathComplexity=288 on `syncMembership()` even after the fix — but every
  smaller-scope invocation is clean, INCLUDING one passing the exact same
  222 files as an explicit list of `lib`'s 17 subdirectories. Reports the
  identical numbers before and after a substantial method rewrite. Concluded
  this is a phpmd tool artifact triggered by its own directory-recursion
  at this codebase's scale, not a real complexity regression (manual count of
  the actual method: ~5, nowhere near 10). Documented exhaustively in the PR
  body rather than silently suppressed or chased further — `check:strict`
  exit code is 1 solely because of this; every other stage (psalm, phpstan,
  full PHPUnit 1083/4808/5-skipped, phpcs) is clean.
- hydra gates diff-scoped: 1 failed (`gate-112 newman-reach`, pre-existing,
  same as #907/#909).
- `opsx-verify`: posted as PR comment
  https://github.com/ConductionNL/learniq/pull/915#issuecomment-5844459253
- **CHANGE 3 COMPLETE** (with the phpmd anomaly documented, not silently
  passed over).

## Change 4: credential-renewal-listener

- Branch: `fix/credential-renewal-listener` (cut from `origin/development`),
  pushed. Commit `840388a`.
- Scope: new `CredentialRenewalListener` (mirrors `ExemptionGrantHandler`'s
  create+link shape) — on `Credential.expire`, creates a renewal `Enrolment`
  (`source: credential-renewal`, `mandatory: true`, learnerId/courseId/
  regulationSlug copied) and writes its id back onto
  `Credential.renewalEnrolmentId`. Added `credential-renewal` to
  `Enrolment.source`'s enum (matches the register's own established
  convention: `admission`/`subject-choice` were added the same way for their
  bridges). Registered in `SchedulingListenerRegistrar`. Closes the expiry
  half of the pre-existing `certification` spec requirement "Auto-enrol on
  renewal or content-version change" (MODIFIED delta, kept the original
  scenario text per validator's requirement, added a new expiry-specific
  scenario). 5 unit tests, 12 assertions.
- Files: `lib/Listener/CredentialRenewalListener.php`,
  `lib/AppInfo/Registrar/SchedulingListenerRegistrar.php`,
  `lib/Settings/learniq_register.json` (enum + version bump 0.21.0→0.22.0),
  `tests/Unit/Listener/CredentialRenewalListenerTest.php`,
  `openspec/changes/credential-renewal-listener/*`.
- Verification so far: `php -l` exit 0 on all touched files; `vendor/bin/phpcs`
  0 errors (1 pre-existing inherited warning on the registrar);
  `vendor/bin/phpunit --filter CredentialRenewalListenerTest` exit 0 (5/5, 12
  assertions); targeted `vendor/bin/phpmd` on the 2 touched PHP files: 0
  findings; `npm run check:json-strict`/`check:register` exit 0.
- `composer check:strict` and diff-scoped hydra gates launched in background
  (TMPDIR outside the repo this time, avoiding the earlier `.tmp` lint-bloat
  mistake). Results to follow.
- PR: https://github.com/ConductionNL/learniq/pull/918
- `composer check:strict`: `ALL CHECKS PASSED` (1079 tests / 4802 assertions
  / 5 skipped; phpmd clean this time — confirms the #915 anomaly was scoped
  to that specific file, not a general full-lib flake). hydra gates
  diff-scoped: 2 failed, both inherited (`gate-112 newman-reach`, same as
  every prior PR; `gate-53 effective-manifest-crossref` broken checker, same
  ESM/CommonJS bug as #909).
- `opsx-verify`: posted as PR comment
  https://github.com/ConductionNL/learniq/pull/918#issuecomment-5844495135 —
  no CRITICAL/WARNING issues.
- **CHANGE 4 COMPLETE.**

## Change 5: attendance-threshold-calculation

- Branch: `fix/attendance-threshold-calculation` (cut from `origin/development`),
  pushed. Commit `124394a`.
- Scope, in two independent tracks:
  1. **Declarative** (satisfies the pre-existing `attendance` spec
     requirement "Threshold crossing is a declared calculation trigger"
     completely): `AttendanceThreshold.x-openregister-calculations`
     (`unexcusedLesuren`, `isThresholdCrossed`) backed by a new
     `x-openregister-aggregations` per-cohort count of absent-unexcused
     `AttendanceRecord`s — the same aggregate-then-compare shape
     `Regulation.coveragePercent`/`ragStatus` already use. The existing
     `thresholdCrossed` notification now fires for real (also added
     `coordinator` to its recipients, matching `onCross.notifyRoles`'
     documented default). Named platform gap: this is a per-COHORT
     approximation, not per-individual-learner — OpenRegister's aggregation
     DSL cannot parameterise `@self.*` by a learner id that doesn't exist on
     the shared threshold definition.
  2. **Guarded manual bridge** (so `AttendanceFlagCreationHandler` can
     actually create an `AttendanceFlag` today): a `check-threshold`
     self-loop transition + 5 transient `checked*` properties + new
     `AttendanceThresholdCrossingGuard`, plus a 2-part correction to
     `AttendanceFlagCreationHandler`: (a) match on `getAction() ===
     'check-threshold'` instead of a `getTo() === 'threshold-crossed'` state
     that no transition in this schema (or OpenRegister's TransitionEngine)
     can ever produce; (b) **found and fixed a third, previously
     undocumented bug**: the handler called
     `$event->getContext()` — verified by reading
     `openregister/lib/Event/ObjectTransitionedEvent.php`'s full public
     method list that this method does not exist on the real class at all.
     Replaced with reads from `$event->getObject()->jsonSerialize()`.
- Files: `lib/Settings/learniq_register.json` (aggregation + 2 calculations +
  1 transition + 5 properties + notification recipients + version bump
  0.21.0→0.22.0), `lib/Lifecycle/AttendanceThresholdCrossingGuard.php` (new),
  `lib/Lifecycle/AttendanceFlagCreationHandler.php` (corrected + refactored
  into `extractCrossingDetail()`/`saveFlag()` to stay under phpmd's
  complexity/length thresholds), 3 new test files (16 tests total: 5 guard +
  5 handler + 6 register).
- Verification: `php -l` exit 0 on all 3 touched/new PHP files; targeted
  `vendor/bin/phpcs` 0 errors (1 pre-existing inherited warning); targeted
  `vendor/bin/phpmd` on both Lifecycle files: 0 findings (after extracting
  the two helper methods — the first pass had 3 real findings on
  `createFlag()`, confirmed genuinely new by comparing against
  `origin/development`'s version of the same file, which has 0 findings);
  `vendor/bin/phpunit --filter 'AttendanceThreshold|AttendanceFlagCreationHandler'`
  exit 0 (16 tests, 51 assertions) — **this is the test that proves a real
  crossing creates a real AttendanceFlag**, the brief's explicit acceptance
  bar; `npm run check:json-strict`/`check:register` exit 0 (the register
  guard-class cross-reference check specifically caught a missing
  `AttendanceThresholdCrossingGuard` class before it was written).
- `composer check:strict` and diff-scoped hydra gates launched in background.
  Results to follow.
- Follow-up commit `53832f0` fixed a real phpstan finding caught in the first
  `check:strict` run: `checkedMetricValue` read via `?? ''` already
  eliminates `null`, so a follow-on `=== null` check was unreachable —
  removed.
- PR: https://github.com/ConductionNL/learniq/pull/926
- `composer check:strict` (second, clean run): `ALL CHECKS PASSED` (1090
  tests / 4841 assertions / 5 skipped). hydra gates diff-scoped: 2 failed,
  both inherited (`gate-112 newman-reach`; `gate-53
  effective-manifest-crossref` broken checker, same ESM/CommonJS bug as
  #909/#918).
- `opsx-verify`: posted as PR comment
  https://github.com/ConductionNL/learniq/pull/926#issuecomment-5844575769 —
  no CRITICAL/WARNING issues.
- **CI fix pass (2026-09-26, per CI-READ-2026-09-26.md)**: PR #926 carried one
  NEW red — `check:schema-l10n` (8 uncovered strings, ratchet 2416→2424) —
  from the 5 transient `checked*` properties' titles/descriptions having no
  catalogue key. Fixed: added English identity + Dutch translation entries
  for all 10 strings to `l10n/en.json`/`l10n/nl.json`, rebuilt
  `l10n/en.js`/`l10n/nl.js` via `npm run l10n:build`. Verified standalone:
  `npm run check:schema-l10n` exit 0, 2414 uncovered (2 below baseline, not
  lowered — out of scope). `npm run check:specs` re-run clean. Commit
  `5867499`, pushed, PR body edited (comment
  https://github.com/ConductionNL/learniq/pull/926#issuecomment-5846681888).
  PRs #907/#909/#915/#918 confirmed fully green per CI-READ, no action
  needed on them.
- **CHANGE 5 COMPLETE. ALL FIVE CHANGES DONE. CI FIX PASS DONE.**

---

## Lane summary

| # | Change | Branch | PR |
|---|---|---|---|
| 1 | registry-component-fix | `fix/registry-component-fix` | https://github.com/ConductionNL/learniq/pull/907 |
| 2 | session-roster-notifications | `fix/session-roster-notifications` | https://github.com/ConductionNL/learniq/pull/909 |
| 3 | cohort-group-provisioning | `fix/cohort-group-provisioning` | https://github.com/ConductionNL/learniq/pull/915 |
| 4 | credential-renewal-listener | `fix/credential-renewal-listener` | https://github.com/ConductionNL/learniq/pull/918 |
| 5 | attendance-threshold-calculation | `fix/attendance-threshold-calculation` | https://github.com/ConductionNL/learniq/pull/926 |

None stacked — every branch was cut fresh from `origin/development` per
LANE-RULES (none of the five changes depended on another). No PR was merged
(LANE-RULES: "Do NOT merge"). CI was not polled on any PR.

**Two incidents worth a future lane's attention** (both already resolved for
this lane, both documented above at first occurrence):
1. The orchestrator-shared session scratchpad is one directory for every
   lane — a filename collision with another lane silently overwrote a log
   before it could contaminate a real result here, but a future lane should
   default to a lane-local log path from the start.
2. `composer phpmd`'s full-`lib` single-directory-argument scan is
   non-deterministic relative to its own subset-invocations at this
   codebase's ~222-file scale — reproduced and documented in PR #915 rather
   than silently chased or suppressed.

**Left blocked/deferred, all named explicitly in their own PR/proposal,
none silently dropped:**
- True per-individual-learner (not per-cohort), continuously-live attendance
  threshold crossing detection — needs either a data-model change
  (per-learner threshold-state rows) or OpenRegister's `autoWhen`/
  `lifecycle-auto-transitions` capability landing and being adopted (PR #926).
- The content-version-change half of "Auto-enrol on renewal or
  content-version change" — needs a content-version concept on `Course` that
  does not exist today (PR #918).
- `RolloverExecutionService`'s computed cohort-name string vs. the real
  provisioned NC group id this lane's fix now writes — a residual
  disagreement named but not reconciled (PR #915).
- Holiday exclusion in the attendance rolling-4-week window; the DUO
  verzuimloket melding pipeline — both untouched, per the triage's own
  scoping (PR #926).
