# Lane log: r2-portal-learniq (learniq, clone lq-privacy)

Resume rule: read this file, `git status`, continue from the first change not marked DONE.
This file is never staged. The clone's LANE-LOG.md is skip-worktree and belongs to the earlier lane.

## 1. assignment-portal-wiring: DONE

- Branch `feat/assignment-portal-wiring` (--no-track from origin/development 721b28ac), commit e5a64b27, pushed.
- PR https://github.com/ConductionNL/learniq/pull/1068 (not merged).
- What: createSubmission declares portaliq's file field (fieldConfigs.attachmentRefs, #745 keys) and minTrust low;
  createSubmission and studentSubmissions scope by a new scalar Submission.learnerRef; new SubmissionOwnerStamp
  (create + update) stamps learnerIds/learnerRefs/tenant_id on a portal hand-in from LearnerProfile + Assignment,
  derives learnerRef from learnerIds[0] on every other write, refuses any write without learners or tenant;
  learnerIds and tenant_id left Submission.required (OR validates required BEFORE ObjectCreatingEvent, so a stamp
  can never satisfy it). New LearnerProfileLookup (byRef, refForUser). Submission 0.3.0, info.version 0.24.10.
- Verified: openspec validate 0; diff-scoped phpcs/phpmd/phpstan/psalm 0; 38 targeted tests 0 (23 new);
  check:strict 1 (14 inherited Register/Settings reds, identical by name to a clean development control; phpmd
  inherited registrar findings); lint 0; format 0; schema-l10n 0; l10n-js 0; hydra gates 2 (gate-112 inherited,
  gate-53/68 tooling: ES-module require error from the workspace package.json).
- opsx-verify: pass, no CRITICAL/WARNING.
- Findings for others (in PR body): portal draft cannot be handed in (needs a hand-in endpoint action); portaliq
  create stamps subjectRef not the resolved claim; ExcuseRequest has the same required problem; parentChildren
  array scope never matches; #1027 claims the same versions.
- Time: about 2 h (includes the shared reading for change 2).
- Catch-up (after opening): development moved to register 0.25.1; merged origin/development 3b2253ca into the branch
  (merge 695739c4), re-applied Submission + bumped info.version to 0.25.2, rebuilt l10n js. Full PHPUnit after
  merge: 1534 tests, 12 failures, identical by name to a control run on development (1511 tests). Pushed, PR body
  updated.

## 2. assessment-portal-endpoints: DONE

- Branch `feat/assessment-portal-endpoints` (--no-track from origin/development 3b2253ca).
- Killed by an account rate limit while writing the studentTests manifest (uncommitted work survived on disk).
  Resumed: change 1 confirmed pushed (695739c4), PR #1068 OPEN. Change 2 files all present, not yet committed.
  Coordinator: portaliq #745, #746, #748, #749 merged on portaliq development; portaliq #750 (list membership in
  the scope check) open.
- Committed bdb47edd (all tasks 1-8 implemented, 109 targeted tests green) and pushed the branch, no PR yet.
  Left: task 9 (docs), tick tasks.md, diff-scoped psalm/phpstan, check:strict once, lint/format, gates, PR, verify.
- Merged origin/development 03b61c1c (9afdc914): register -> 0.27.2 with AssessmentResult 0.2.0, l10n rebuilt,
  stub keeps dev's deleteObject + my runAs. Head 65724f1b pushed.
- PR https://github.com/ConductionNL/learniq/pull/1096 (not merged).
- Verified: validate 0; diff-scoped phpcs/phpmd/phpstan/psalm 0; 109 targeted tests 0; mutation check caught;
  check:strict 1 (12 inherited Register/Settings failures, identical by name to a full control on development
  03b61c1c; inherited registrar phpmd); lint 0; format 0; schema-l10n 0; register 0; l10n-js 0; gates 2
  (gate-112 inherited, gate-53/68 tooling); all auth/route gates pass.
- opsx-verify: one WARNING (design named pre-split PortalAttemptStore) fixed in 65724f1b; then clean.
- Time: about 3 h including the rate-limit resume.

## 3. Catch-up of PR #1068: DONE
- development gained #1027 (Submission changes) after #1068's last merge; merge it in, re-check, push.
- Merged origin/development 03b61c1c into feat/assignment-portal-wiring (743403a7): Submission re-applied on top
  of #1027's 0.3.0 -> 0.4.0, info 0.27.3 (0.27.2 is #1096's), registrar keeps both listeners, l10n rebuilt,
  parentChildren docblock points at portaliq #750. #1027's SubmissionResubmissionRegisterTest asserted an exact
  version -> now a floor (ee576233). Artifacts updated (cf382e28). Full PHPUnit: 1675 tests, the 12 inherited
  failures by name (control on 03b61c1c). Pushed, PR #1068 body updated.
- #1020 merged meanwhile: LearnerProfileLookup::refForUser duplicates LearnerRefResolver::resolve; both PRs carry the
  identical LearnerProfileLookup file, fold after both land.

## 4. CI read (once, at lane end)
- 09-27 late: CI read found BOTH PRs CONFLICTING (development gained #1025, #1021, #1044; register 0.27.4).
  #1068: merged origin/development 46346f6c locally (52e952cd, Submission kept at 0.4.0, info 0.27.5, l10n rebuilt,
  check:register 0, schema-l10n 0). Full PHPUnit for it was killed (exit 137) by the WSL crash; NOT pushed yet.
  #1096 (65724f1b) pushed but needs the same catch-up (info 0.27.6).
- After WSL restart: resume = verify + push 52e952cd, catch up #1096, then the one CI read of both.
- #1068 catch-up verified after restart: full PHPUnit 1706 tests, 12 failures identical by name to control on
  46346f6c (1683 tests). Pushed 52e952cd, PR body updated.
- #1096 catch-up: merged 46346f6c (295fc3c1), AssessmentResult re-applied, info 0.27.6, l10n rebuilt; full PHPUnit
  1751 tests, 12 failures identical by name to control on 46346f6c; gates unchanged (gate-112 inherited, gate-53
  tooling; 49 of 50 ran). Pushed, PR body updated. openspec validate still 0, 17/17 tasks ticked.
- CI read (once): #1068 at 52e952cd: 32 pass, 9 skip, 1 pending, 2 red (phpmd, Hydra Gates full-repo gates
  3/25/49/55) - both identical to development's own push run at 46346f6c (run 36326716687) -> inherited.
  #1096 at 295fc3c1: Code Quality never ran (PR turned CONFLICTING again: #1026, #1029 landed, register now 0.27.6).
- Landing note added to both PR bodies: conflicts are register info + l10n only; re-apply schema, re-bump above
  development, rebuild l10n, full suite; expected red = the 12 inherited Register/Settings failures.

## LANE COMPLETE (27 Sep). Both branches pushed: #1068 52e952cd, #1096 295fc3c1. Not merged.
