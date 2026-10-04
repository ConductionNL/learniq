# LANE-LOG r5-timetabling-a (learniq clone lq-contracts)

Scratch files for this lane: .tmp/r5a/ (not the shared session scratchpad).

## 1. timetabling-bulk-change-weeks: DONE
- branch feat/timetabling-bulk-change-weeks, PR https://github.com/ConductionNL/learniq/pull/1248
- check:strict exit 0 (2222 tests), lint 0, format 0, schema-l10n 0, gate 101 0; hydra gates: 51 and 60 fixed, 3/25/49/55 inherited
- opsx-verify static: pass, warning no docs page
- VO seed is a room change (lessons in the set are completed days)

## 2. timetabling-display-screens: DONE
- branch feat/timetabling-display-screens, PR https://github.com/ConductionNL/learniq/pull/1294
- check:strict exit 0 (2219 tests), lint/format/l10n/manifest/build 0; hydra gates exit 5, all inherited (3, 25, 49, 55, 60)
- opsx-verify static: pass, warning no docs page

## 3. timetabling-elective-lesson-signup: DONE
- branch feat/timetabling-elective-lesson-signup, PR https://github.com/ConductionNL/learniq/pull/1369
- check:strict exit 0 (2224 tests), lint/format/l10n/register/manifest 0; hydra gates exit 5, all inherited
- opsx-verify static: pass, warning no docs page

## 4. timetabling-contact-hours: DONE (stacked on #1312)
- branch feat/timetabling-contact-hours, PR https://github.com/ConductionNL/learniq/pull/1370
- check:strict exit 0 (2227 tests); hydra gates exit 5, inherited only after the icon fix
- MV2A example data deferred (mbo.py is being edited by #1312)
## 5. timetabling-enrolment-forecast: DONE (stacked on #1312)
- branch feat/timetabling-enrolment-forecast, PR https://github.com/ConductionNL/learniq/pull/1388
- check:strict exit 0 (2228 tests); hydra gates exit 5, inherited only

## CI read once at lane end (2026-09-28 late)
- #1248: 34 pass, 2 fail (Hydra Gates: gates 3/25/49/55/60, the same inherited set as local; Quality Report follows it)
- #1294, #1370: 4 checks only (quality workflow not run yet or throttled): count is incomplete, not green
- #1369: CodeQL Analyze x2 failed on GitHub API rate limit (infra)
- #1388: CONFLICTING with development at read time; 3 pending
- No planninq work was needed for these five changes (pn-rostering untouched).

## Development merges for landing (2026-09-29)
- #1248 9d34f95d (Session version floor in TimetableVisibilityRegisterTest), landed 83101ced
- #1294 a0a5e570, landed 806b755c
- #1369 33689f1b, landed 9d2b4b69
- #1370 4a807541 (restacked: dev tree + own diff over 64e30a3c), landed ac906215
- #1388 ae87f0c6 (restacked the same way), pushed; strict 0 (2460 tests)
