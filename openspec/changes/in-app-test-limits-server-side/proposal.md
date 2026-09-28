---
kind: code
depends_on: [assessment-portal-endpoints]
---

# Proposal: in-app-test-limits-server-side

## Summary

A pupil who takes a test on the portal is held to `maxAttempts` and the time limit by the server (#1096). The app's own test screen was held to them by the browser only: it counted nothing, sent its own start time and always attempt 1, and nothing stopped answers after the time was up. This change enforces both on the server for the in-app screen, with the same parts the portal endpoints use.

## Motivation

Found by the r2-portal-learniq lane (TRACKER-R2): "the in-app test screen does not enforce maxAttempts or the time limit server-side". What the server checked for an in-app attempt before this change: the availability window and the access code on start (`AssessmentAttemptGateListener`, learniq#946), and who may write and score it (`AssessmentResultIntegrityListener`, learniq#948). What it did not check:

- **Attempts.** A second `POST` of an AssessmentResult started another attempt on a one-attempt test.
- **The start.** `startedAt` came from the browser's clock and `attemptNumber` was always 1; a learner could start the clock in the future and give themselves more time.
- **The deadline.** Answers saved after the time limit, with or without extra time, were accepted.

## Affected Projects

- [ ] Project: `learniq`: `AssessmentAccessPolicy`, `PortalAssessmentCatalogue`, `AssessmentAttemptGateListener`, new `AssessmentAttemptLimits` and `AssessmentAttemptTimeLimitListener`, `IntegrityListenerRegistrar`, `TakeAssessmentView`.

## Scope

### In Scope

- `AssessmentAccessPolicy::attemptsBlock()`: the attempts rule, moved from `PortalAssessmentCatalogue` so the portal and the app share it.
- `AssessmentAttemptLimits`: start rules (window, access code, attempts left) and the late-answer rule, on `PortalAttemptReader` and `PortalAttemptClock`.
- The attempt gate refuses a start past `maxAttempts` and sets `startedAt` and `attemptNumber` from the server.
- `AssessmentAttemptTimeLimitListener`: the learner cannot move `startedAt` or `attemptNumber`; after the deadline plus 30 seconds (with extra time) answers stop changing, and the save, a hand-in included, goes through with the answers stored in time.
- The test screen shows a translated sentence when the attempts are used.

### Out of Scope

- Saving answers during the attempt in the test screen. The screen saves at hand-in, so a hand-in that arrives after the deadline plus the grace keeps no answers; the screen's own timer hands in at zero, so this bites only a stopped or manipulated clock.
- The screen's timer does not count a learner's extra time; the server does. Showing the server's deadline on the screen is a follow-up.

## Approach

Reuse the portal's parts instead of copying them: `PortalAttemptReader` counts attempts and reads the test and accommodations, `PortalAttemptClock` computes the deadline with extra time and the grace, `AssessmentAccessPolicy` decides window, code and attempts for both. The in-app rules run where the in-app writes already pass: the create gate and a pre-write listener on the update.

## New Dependencies

None.

## Impact

A learner on the in-app screen gets the same limits as on the portal. The portal path is unchanged; its writes run as the pupil and meet the same rules, which agree with its own.

## Cross-Project Dependencies

None.

## Risks

### Risk 1: A late hand-in from the screen keeps no answers

**Severity:** Medium. **Mitigation:** Named in Out of Scope. The screen hands in at zero on its own timer, within the 30-second grace. Autosave in the screen removes the risk and is the follow-up.

## Rollback Strategy

Revert the merge commit.
