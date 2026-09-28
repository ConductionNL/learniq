---
kind: code
depends_on: [in-app-test-limits-server-side]
---

# Proposal: test-screen-autosave-and-deadline

## Summary

Since #1152 the server holds the in-app test screen to the time limit, extra time included, but the screen still counted down from the plain time limit and saved answers only at hand-in. The server now stamps the attempt's deadline, extra time included, and the screen counts down to it and saves answers as the learner works.

## Motivation

`in-app-test-limits-server-side` names both as follow-ups (Out of Scope): "Saving answers during the attempt in the test screen" and "The screen's timer does not count a learner's extra time; the server does. Showing the server's deadline on the screen is a follow-up." Its Risk 1: a hand-in that arrives after the deadline plus the grace keeps no answers; autosave removes that risk. TRACKER-R2 "ROUND 3 FINAL": in-app test autosave and server deadline in the timer.

## Affected Projects

- [ ] Project: `learniq`: `AssessmentAttemptLimits`, `AssessmentAttemptTimeLimitListener`, AssessmentResult `deadlineAt` in the register, `TakeAssessmentView`, new `src/utils/attemptClock.js`, l10n.

## Scope

### In Scope

- `AssessmentResult.deadlineAt`, stamped by the server on start: start plus time limit plus the learner's granted extra time (the portal's `PortalAttemptClock`), null without a time limit. The learner cannot change it.
- The screen counts down to `deadlineAt`, corrected by the server clock offset from the response's Date header, and keeps counting from it when an attempt is resumed. Without `deadlineAt` (an attempt started before this change) it counts down from the time limit as before.
- Answers are saved 1.5 seconds after the last change (PATCH of `responses`), with a status line; a resumed attempt shows the saved answers.

### Out of Scope

- The portal's test screen: portaliq has its own screen and already reads `deadlineAt` from the portal endpoints.

## Approach

Reuse `PortalAttemptClock` for the deadline, as the late-answer rule does; add `deadlineAt` to the listener's fixed start fields. A small tested helper holds the countdown and payload logic.

## New Dependencies

None.

## Impact

A learner with extra time sees their full time. Answers survive a closed tab.

## Cross-Project Dependencies

None.

## Risks

### Risk 1: Autosave writes every few seconds

**Severity:** Low. **Mitigation:** Debounced to one PATCH per pause; only while the attempt is in progress.

## Rollback Strategy

Revert the merge commit.
