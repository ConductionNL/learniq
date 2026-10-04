# Lane log: r3-portal (learniq part, clone lq-contracts)

Resume rule: read this file, `git status`, continue from the first change not marked DONE. Never staged.
Portaliq part: see pq-guard/LANE-LOG-r3-portal.md (1a #805 DONE, 1b #810 DONE).

## 2. portal-assignment-hand-in-endpoint: DONE
- Branch `feat/portal-assignment-hand-in` (--no-track from origin/development 0171a896).
- Built: SubmissionWindowGuard refusal constants; PortalSubmissionHandIn (guard picks submit/submitLate, runAs pupil
  TransitionEngine, guard refusal on write -> 422); PortalSubmissionController + route portalSubmission#handIn;
  PortalMessages 4 reasons; catalogue keys for them + the 9 uncatalogued #1096 PortalMessages texts (nl);
  handIn action (rowField submissionId, rowWhen lifecycle draft) + studentSubmissions.rowActions; docs 04-assignments.
- Tests: PortalSubmissionHandInTest (8, real guard), PortalSubmissionControllerTest (4), provider test (+1, list +handIn).
  Mutation checks (ownership, runAs, late choice) caught. Diff checks phpcs/phpmd/phpstan/psalm 0.
- Next: check:strict once (background), control of the failure set, lint/format/l10n, gates, commit, push, PR, verify.
- check:strict 1: phpmd 1 inherited (CaseListenerRegistrar), PHPUnit 1932 tests 12 failures = the known access-control
  set, identical by name on a clean origin/development 0171a896 worktree (control removed after). lint 0; format 0;
  l10n-js 0; schema-l10n 0; gates 8, none naming this PR's files (3/25/49/55/62/112/113 inherited, 53 tooling).
- Commit 7089eb62, pushed. PR https://github.com/ConductionNL/learniq/pull/1142 (not merged).
- opsx-verify: no CRITICAL/WARNING; suggestion: portaliq e2e once #805 lands.
- Time: about 2 h.

## LANE COMPLETE: portaliq #805, #810; learniq #1142. None merged.
