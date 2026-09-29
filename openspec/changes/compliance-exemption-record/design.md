# Design: record an exemption from a mandatory training, with the reason

## Context

At development `acdf1dd5`:

- `lib/Lifecycle/ExemptionDecisionGuard.php:49` passes only when `decisionRationale` and `policyReference` are non-empty; it is bound to `ExemptionCase` `grant` and `reject`.
- `lib/Service/ComplianceRollupService.php:165-179` counts obligations and covered per learner; `:222` `isCovered`.
- `lib/Settings/learniq_register.json` `ExemptionCase` fields (`groundsKind`, `groundsDescription`, `decisionRationale`, `policyReference`, `decidedBy`, `decidedAt`) are the template for the new schema.
- `lib/Listener/ExemptionGrantHandler.php` bridges `ExemptionCase` to a grade entry and is not involved: a regulation exemption creates no grade.

## Goals / Non-Goals

**Goals**
- A compliance exception is a record with a reason, an owner and an end date, and the roll up honours it.

**Non-Goals**
- Exam board exemptions (built).
- Automatic exemptions from role rules.

## Decisions

### D1: A new schema, not ExemptionCase

ExemptionCase points at a curriculum component and creates a grade on grant. A regulation exemption has neither, so overloading it would make two meanings share one guard and one bridge.

### D2: Excused is its own number

Removing a learner from the obligations silently would hide the exemption. The roll up reports excused separately so an auditor can see it.

### D3: Guard reuse by composition

The new guard calls the same rationale check and adds the requester-differs-from-decider rule.
