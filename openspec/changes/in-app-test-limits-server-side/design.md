# Design: in-app-test-limits-server-side

## Architecture Overview

```
create AssessmentResult ──▶ AssessmentAttemptGateListener ──▶ AssessmentAttemptLimits::start()
                                                                 ├─ AssessmentAccessPolicy (window, code, attemptsBlock)
                                                                 └─ PortalAttemptReader::attemptsFor()
update AssessmentResult ──▶ AssessmentAttemptTimeLimitListener ─▶ AssessmentAttemptLimits::answersLate()
                                                                 ├─ PortalAttemptReader (exam, accommodations)
                                                                 └─ PortalAttemptClock (deadline, extra time, grace)
```

## Decisions

### D1. One attempts rule for portal and app

`maxAttempts` was decided in `PortalAssessmentCatalogue::maxAttempts()`, a private method. It moves to `AssessmentAccessPolicy::attemptsBlock()`, the pure class that already holds the window and access-code rules both callers use; the catalogue calls it, so the portal's behaviour is unchanged (its tests are unchanged and green).

### D2. The server sets the start

The time limit is only as good as `startedAt`. The gate sets it from the server's clock on create, with `attemptNumber` as attempts so far plus one, and the time-limit listener refuses a learner's change to either. The portal already set both server-side; its values now agree with the gate's.

### D3. Late answers are kept out, the hand-in goes through

Refusing a late save would leave the attempt in progress forever (the screen's hand-in is a save followed by the `submit` transition). So after the deadline plus the grace the listener restores the stored answers in the save and lets the rest through, the way the portal hands a late attempt in with the answers it had. Only the learner's answers are compared (item and response); the submit save that adds auto scores to unchanged answers passes untouched, which the portal's automatic hand-in after the deadline depends on.

### D4. A listener of its own

The rules could live in `AssessmentResultIntegrityListener`, but that class sits at phpmd's complexity and coupling limits, and the rules are about time, not ownership. A separate pre-write listener on the same event, registered directly like the integrity rules, keeps both readable. The start rules move out of the gate into `AssessmentAttemptLimits`, which keeps the gate's constructor under the parameter limit.

## Declarative-vs-imperative decision (ADR-031)

| Behaviour | Path | Rationale |
|---|---|---|
| attempts, start stamp | imperative, creation-time veto | OpenRegister runs no lifecycle guard before an object's first insert (as for the existing gate) |
| late answers, fixed start | imperative, pre-write listener | compares stored and incoming objects against the clock and the learner's accommodations |

## Security Considerations

Every rule runs on the server for any non-admin caller with a session; the browser's values for `startedAt`, `attemptNumber` and late answers are ignored. Admins and system context bypass, as with the existing gate and integrity rules.

## Seed Data

No schema change.
