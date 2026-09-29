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
