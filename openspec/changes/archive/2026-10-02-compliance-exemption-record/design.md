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

## Built (30 Sep 2026, build-all lane 4)

What changed against the decisions above, from the code at HEAD:

- **Who may ask.** `create` is `authenticated`; `RegulationExemptionRequestStamp` (on `ObjectCreatingEvent` and `ObjectUpdatingEvent`) lets a member of `compliance-officers` or `admin` ask for anyone and anyone else only for a person whose `LearnerProfile.managerId` names them. A failed profile lookup refuses. This is the proposal's "scoped to the manager's direct reports" without a custom route: the objects API is the only write path, so gate 7 has no new route to check. `team-leads` is not a scope of its own, because a team lead who is nobody's `managerId` has no reports.
- **The server decides who asked.** The stamp sets `requestedBy` to the session user, `lifecycle` to `requested` and clears the decision fields on create, and restores `requestedBy` on update. Without it a requester could write another officer's id into `requestedBy` and then grant their own request.
- **The decider is stamped.** `grant` and `reject` run `StampTransitionActorAction` into `decidedBy` and `decidedAt`, as the external training verification does.
- **D3 as built.** `RegulationExemptionDecisionGuard` calls `ExemptionDecisionGuard::check()` for the rationale and policy reference, then, on `grant` only, refuses the requester and asks for `validUntil` (on or after `validFrom`). A requester may reject their own request, which is a withdrawal.
- **The period is in days.** `validUntil` is the last day the exemption applies; the roll-up compares dates, so it lapses the day after (spec scenario "The exemption lapses"). `expire` exists as a transition for the record, the roll-up does not depend on it.
- **No dialog file.** The request is the index page's standard create form; there is no custom dialog, so there is no `src/modals/` file to add.
- **Excused on screen.** The department compliance widget shows an Excused column next to Coverage.
