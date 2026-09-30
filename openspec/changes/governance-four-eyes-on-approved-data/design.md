# Design: stop one person changing data that has already been approved

## Context

At development `acdf1dd5`:

- `lib/Lifecycle/ReportPeriodLockGuard.php:6` blocks `publish` and `republish` after `ReportPeriod.isLocked`, unless the acting user holds admin, mentor or principal.
- The guard composes `FraudCaseBlockGuard`; OpenRegister accepts one string in `requires`, so the new rule extends this class and does not add a second guard.
- `lib/Settings/learniq_register.json` `ReportPeriod.lockDate`; `GradeEntry` publish and republish transitions.
- The matrix reachedOn lists `/report-periods`, `/report-cards`, `/grades/entries`.

## Goals / Non-Goals

**Goals**
- No single user can alter a published grade after the period is locked.

**Non-Goals**
- Four-eyes on every schema.
- Changing who may lock a period.

## Decisions

### D1: Extend the existing guard

`requires` takes one guard string, so the correction check is added inside `ReportPeriodLockGuard` after the fraud check, as the guard's own docblock already documents for composition.

### D2: A request object, not a flag

A separate object gives the second person something to approve, and a place to keep the reason and the audit.

### D3: The approvers are the old override groups, and the transition says so

Admin, team leads and administration managers kept their role, but it moves from publishing to approving. The `approve` and `reject` transitions carry an `authorization` list with those groups, which OpenRegister checks on every lifecycle move. `DataCorrectionDecisionGuard` adds what a group list can not say: the person who asked can not approve.

### D4: The publish needs the request, not a role

`ReportPeriodLockGuard` asks `CorrectionApprovals::approvedFor(entry, publisher)`. A request covers the publish when it names the entry, is `approved`, its approver is neither its requester nor the publisher, and the entry carries the approved `proposedValue`. The value check means an approver approves one change, not an open door. The lookup runs without the caller's read rights, because the guard judges facts the publishing teacher may not list. The fraud check still runs first, unchanged.

### D5: The request keeps what was asked

`DataCorrectionRequestStamp` sets `requestedBy` from the session and `currentValue` from the grade, starts the request in `requested`, and clears decision fields a client sends. A correction is for a published or revised grade only, read with the requester's own rights. On every update it puts back the grade, the proposed value, the reason, the value before and the requester.

### D6: Applied is recorded on both objects

After the republish, `CorrectionAppliedHandler` moves the request to `applied` with `appliedBy` and `appliedAt`, and sets `GradeEntry.correctionRequestId`. Both writes run as the system: a teacher may not update a request, and the link is a fact of the publish. The `apply` transition names no group for that reason; its guard allows it only when the grade is published with the approved value. The grade's history then shows the value change and the request that holds requester, approver and reason.

### Follow-ups

Four-eyes on other approved objects (final grades, report cards, attendance) is not in this change.
