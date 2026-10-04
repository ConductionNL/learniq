# Lane r5-timetabling-b log

Clone: /home/rubenlinde/memcap-work/lq-lanes/lq-privacy. Helpers in `.lane-logs/r5ttb-*` (untracked).

## timetabling-lesson-note: DONE
- branch feat/timetabling-lesson-note, PR https://github.com/ConductionNL/learniq/pull/1250
- strict exit 0 (2223 tests), lint/format 0, gates exit 8 all inherited (3, 25, 49, 53, 55, 60, 112, 113)
- e2e written not run (instance off limits)

## timetabling-multi-year-hour-plan: in progress
- DONE: branch feat/timetabling-multi-year-hour-plan, PR https://github.com/ConductionNL/learniq/pull/1312; strict exit 0 (2217), gates exit 8 inherited only

## timetabling-room-utilisation: in progress (drafts in scratchpad ru/)
- DONE: branch feat/timetabling-room-utilisation, PR https://github.com/ConductionNL/learniq/pull/1354; strict exit 0 (2209), lint 0 after fix, gates exit 8 inherited only

## timetabling-standby-slots: in progress (drafts in scratchpad sb/)
- DONE standby: branch feat/timetabling-standby-slots, PR https://github.com/ConductionNL/learniq/pull/1366; strict 0 (2212), lint 0, format 0, gates exit 8 inherited only

## timetabling-visibility-rules: in progress (drafts in scratchpad vis/)
- DONE visibility: branch feat/timetabling-visibility-rules, PR https://github.com/ConductionNL/learniq/pull/1371; strict 0 (2247), lint 0, format 0, gates exit 8 inherited only

## CI read (once, lane end)
- #1250: PHPUnit coverage ratchet red (new code 92.71% vs base 93.80%); fixed with guard/reader edge tests, commit 15e2e002, full PHPUnit 2225 exit 0, pushed. Hydra Gates + Quality Report red on inherited gates only (3, 25, 49, 55, 60). PR body not edited: GitHub API rate limit hit.
- #1354: PHPUnit green; Hydra Gates + Quality Report red on the same inherited gates.
- #1366: CodeQL Analyze red = "API rate limit exceeded for installation" (infra).
- #1312, #1371: CI not yet run beyond CodeQL (green) at read time.
- opsx-verify skill not run per change; tasks were ticked only with file/test evidence.
