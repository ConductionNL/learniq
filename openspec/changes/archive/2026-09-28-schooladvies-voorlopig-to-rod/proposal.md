---
kind: code
depends_on: [rod-bsn-and-school-advice]
---

# Proposal: schooladvies-voorlopig-to-rod

## Summary

DUO wants the voorlopig school advice in ROD within 14 days of giving it, but learniq only sent the advice from `definitief`. Once a SchoolAdvies in `voorlopig` has its advice level and date, learniq now asks integriq for the bron-rod schooladvies exchange (Advies1 only; the ROD field set already allows an empty Advies2) and records the job, so it is sent once.

## Motivation

`openspec/changes/rod-bsn-and-school-advice/design.md`, Open Questions: "Sending the voorlopig advice on its own within DUO's 14-day window (the lifecycle only sends from `definitief`)." Its field table maps Advies1 to `voorlopigAdviesLevel`/`voorlopigAdviesDate` and makes Advies2 optional.

## Affected Projects

- [ ] Project: `learniq`: new `SchoolAdviesVoorlopigRodHandler`, `SchoolAdviesVoorlopigRodJob`, `SchoolAdviesRodTiming`; `TransitionBridgeListenerRegistrar`; SchoolAdvies `voorlopigExchangeJobId` in the register; l10n.

## Scope

### In Scope

- On create or update of a SchoolAdvies in `voorlopig` with `voorlopigAdviesLevel`, `voorlopigAdviesDate` and `learnerId`, and no `voorlopigExchangeJobId`: queue the request (deferred out of the save, hydra gate 61).
- The job re-reads the advice, requests the job with the same target, mapping and scope as the definitief send, and stamps `voorlopigExchangeJobId`.

### Out of Scope

- A lifecycle transition for it: `voorlopig` is the initial state, so the advice goes when it is given rather than on a transition a person has to remember within 14 days.
- Resending after a change of the voorlopig advice.

## Approach

Deferred like the other post-save bridges (`ListenerDeferralService` and an `ActorForwardedJob`).

## New Dependencies

None.

## Impact

A school that records the voorlopig advice meets DUO's 14-day window without an extra step. The definitief send later carries both advices, as before.

## Cross-Project Dependencies

Integriq's bron-rod adapter (integriq #2245 and the ROD work of #1191).

## Risks

### Risk 1: A voorlopig advice entered by mistake is sent

**Severity:** Low. **Mitigation:** The job runs on the next cron tick; the advice is sent once and the definitief message supersedes it.

## Rollback Strategy

Revert the merge commit.
