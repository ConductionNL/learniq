# Design: funding-and-teldatum-checks

## Context
`DataExchangeRunGuard` already gates the `queued -> running` transition on one condition (the OSO/SWV parent-review
gate). `privacy-governance-surfaces` (a sibling, independent PR from the same lane) adds a second, unrelated
condition to the same guard for partner approval. This change adds a third, equally independent condition for the
teldatum pre-flight check, following the identical shape so all three compose without conflict regardless of merge
order.

## Goals / Non-Goals
**Goals:** let a school confirm its pupil count before a ROD job sends it around the 1 Feb/1 Oct teldatum; record
the NOAT/CUMI/NNCA funding-weight classification.

**Non-Goals:** computing or verifying the count automatically (integriq's adapter, not yet built); any UI beyond the
existing generic object-edit form.

## Decisions

### Decision 1: The teldatum condition mirrors the partner-approval shape exactly
Same opt-in boolean (`requiresTeldatumCheck`, default `false`) + own status enum (`teldatumCheckStatus`) pattern
`privacy-governance-surfaces` already established for the same guard file's partner-approval condition. Using the
identical shape for a second, independent condition on the same file keeps the guard's growing set of conditions
uniform and easy to review — each is a self-contained `if` block reading only its own opt-in flag and status field.

### Decision 2: `teldatumCheckStatus` is a human attestation, not a computed verdict
Named explicitly in scope and in the proposal's Risk 1: this change does not query actual pupil counts (that
requires integriq's not-yet-built ROD adapter). `confirmed` means a staff member has checked the count and marked
it confirmed — the same shape ParnasSys's own teldatum-controles process takes (a checklist, not an automated
comparison).

### Decision 3: `fundingWeightCode` lives on `LearnerProfile`, independent of the teldatum-check mechanism
The two P-new rows (P-new-12 count-check, P-new-13 funding-weight) both feed "getting bekostiging right" per
placement.md, but are otherwise unrelated data points — one is a per-job pre-flight gate, the other a per-learner
classification. Kept as two independent additive changes in one small change, not merged into one property.

## Declarative-vs-imperative decision (ADR-031)
| Behaviour | Path | Rationale |
|---|---|---|
| `DataExchangeJob` teldatum-check gate | Imperative (guard extension) | ADR-031 exception, identical justification to the partner-approval condition: a lifecycle `requires` guard is the declarative extension point; extending the one existing `check()` method is the smallest diff |
| `LearnerProfile.fundingWeightCode` | Declarative (plain property) | No conditional logic — a classification value with no derived behaviour |

## Seed Data (ADR-001)
No new seed objects. Existing `DataExchangeJob`/`LearnerProfile` seed/live objects gain the new properties at their
additive defaults (`false`/`not-required`/`null`), requiring no backfill.

## Risks / Trade-offs
- [Risk] `teldatumCheckStatus: confirmed` being set without a real re-check → [Mitigation] named explicitly as a
  process, not a technical, control (proposal Risk 1) — the same limitation ParnasSys's own workflow has.

## Migration Plan
No Nextcloud migration class — declarative OpenRegister schema-register update plus one guard extension. Rollback:
revert the register JSON and `DataExchangeRunGuard.php`; every existing job's `requiresTeldatumCheck: false` default
means a rollback affects zero currently-running jobs.

## Open Questions
None.
