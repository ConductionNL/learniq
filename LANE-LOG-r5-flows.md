# Lane r5-learner-flows (round 5, 2026-09-28)

Clone: /home/rubenlinde/memcap-work/lq-lanes/lq-reports. Changes in order: assignments-double-marking, attendance-self-check-in, enrolment-catalogue-self-signup, enrolment-self-join-work-group, credentials-europass-edci-export, credentials-bulk-reissue (stacked on europass, it depends on it).

## Change 1: assignments-double-marking — DONE (PR #1246)
- Branch feat/assignments-double-marking (from origin/development ed662998), commit bf8a7a3e.
- Built: register (Assignment 0.5.0, Submission 0.5.0, SubmissionMark 0.1.0, info 0.32.0), SubmissionMarkAllocationService, SubmissionMarkReader, SubmissionMarkController (allocate, marks), AllocateMarkersView + header action, MarkSubmissionView double marking modes, 3 demo rows, catalogue nl. Tests: 11 PHP + 6 JS green.
- Open: e2e (no live instance), HE example set rows.
- strict 0 (2214 tests), lint 0, format 0, gates 1 (gate-69 custom-page ratchet, argued), js-unit 1 inherited. opsx-verify pass with e2e gap. Docs added.

## Change 2: attendance-self-check-in — DONE (PR #1273)
- Branch feat/attendance-self-check-in (from origin/development). CheckInWindow, markedVia, CheckIn{Code,}Service, CheckIn/CheckInCode/PortalCheckIn controllers, StudentFlowActions (portal checkIn), SelfCheckInPanel, CheckInPage + menu, registerRowsToSave. 16 PHP + unit-js green.
- strict 1 -> phpcs line fixed + phpcs 0 (tests 2218, 0 fail). gates 1 (gate-69 argued). No QR image (no new dependency). e2e open.

## Change 3: enrolment-catalogue-self-signup — DONE (PR #1349)
- Branch feat/enrolment-catalogue-self-signup. selfEnrolment, approve/decline, created-trigger notifications, manager update rule, CatalogueReader/SignUpService, Catalogue + PortalCatalogue controllers, CatalogueFlowActions, CourseCatalogue page, menu presets. 30 tests.
- strict 0 (2217). gates 1 (gate-69 argued) after fixing gate 7 and 68.

## Change 4: enrolment-self-join-work-group — DONE (PR #1368)
- Branch feat/enrolment-self-join-work-group. WorkGroup, membership service with lock, reader, controllers + portal, MyWorkGroups, SubmitWorkView group hand-in. strict 1 -> phpcs fixed (tests 2213 green). gates 1 (gate-69 argued).

## Change 5: credentials-europass-edci-export — DONE (PR #1373)
- Task 1 already by #1178. Builder, EuropassIssuer, proofFor, JwsProofVerifier, Europass controller, verifyEuropass. strict 0 (2248), gates 0 after fixes.

## Change 6: credentials-bulk-reissue — DONE (PR #1389, stacked on #1373)
- strict 0 (2257), gates 1 (gate-69 argued). 11 tests.

## CI read (once, 2026-09-28 late)
- #1246 merged. #1349: PHPUnit coverage guard red (new code coverage -5.93%); Hydra Gates red = same 5 full-repo gates as merged #1246 (inherited). #1273 and #1373 conflicting (after #1246 landed), CI not run. #1368 only CodeQL Analyze red.
- Fix: add controller/portal tests on #1349, #1273, #1368.
- Coverage tests pushed on #1349 (b748e66d), #1273 (dd0bc95f), #1368 (a71dba83). Lane done.
