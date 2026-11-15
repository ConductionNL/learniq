---
kind: code
depends_on: [access-control-ratchet-compliance]
---

# Proposal: lvs-score-freeze

## Summary

Since LvsResult stopped being `appendOnly` (learniq#1124), coordinators and compliance officers can edit the score of an LVS result after a coordinator has verified it. This change freezes the score fields once a result is verified, with a listener in the style of `AssessmentResultIntegrityListener`.

## Motivation

The design of `access-control-ratchet-compliance` names this as a follow-up: "Dropping `appendOnly` lets the writer groups edit an imported LVS score; the audit trail keeps each version, and a freeze listener in the style of `AssessmentResultIntegrityListener` is a named follow-up." A verified LVS result feeds report cards and trends, so a later edit changes evidence other features already trusted.

## Affected Projects

- [ ] Project: `learniq`: new `LvsResultFreezeListener`, `IntegrityListenerRegistrar`, the LvsResult description in `lib/Settings/learniq_register.json`.

## Scope

### In Scope

- Once stored as `verified` or `archived`, the score fields (`provider`, `instrument`, `moment`, `takenAt`, `rawScore`, `vaardigheidsscore`, `niveau`, `referentieniveau`, `dle`, `dataExchangeJobId`, `tenant_id`) cannot change.
- A verified result can only move on to `archived`; an archived result is final.
- `learnerId` (learner merge) and `assessmentResultId` (a back-link) stay writable.
- Nextcloud admins and system context (the import job) are not policed.

### Out of Scope

- Delete: the schema grants no delete, so only admins delete, as before.
- Corrections to an `imported` row: still allowed, that is what the review before `verify` is for.

## Approach

A pre-write `ObjectUpdatingEvent` listener compares the stored row with the incoming one and stops the event with a reason, as the AssessmentResult integrity listener does.

## New Dependencies

None.

## Impact

A coordinator who needs to correct a verified score now asks an admin; the refusal names the reason `lvs-result-verified`.

## Cross-Project Dependencies

None.

## Risks

### Risk 1: A re-import of a verified result is refused

**Severity:** Low. **Mitigation:** The import runs in system context, which is not policed.

## Rollback Strategy

Revert the merge commit.
