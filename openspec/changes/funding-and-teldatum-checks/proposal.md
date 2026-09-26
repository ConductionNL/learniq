---
kind: config
---

# Proposal: funding-and-teldatum-checks

## Summary
Two additive, declarative changes closing P-new-12 and P-new-13: a pre-flight check on the ROD `DataExchangeJob` gated on the 1 February / 1 October teldatum (the DUO funding count dates), and a NOAT/CUMI/NNCA funding-weight property on `LearnerProfile`. Both are additive fields plus one more independent condition on the existing `DataExchangeRunGuard`, following the exact shape `privacy-governance-surfaces`'s partner-approval gate already established on the same guard — no OpenConnector adapter work, no new schema, no PHP beyond the guard's one extra condition.

## Motivation
`P-new-12` (ParnasSys: "Teldatum 01-02-2026: controles voor bekostiging", "ROD leerlingaantallen") and `P-new-13` (NOAT/CUMI/NNCA culturele-achtergrond registration for funding weging) are both statutory bekostiging (funding) mechanics ParnasSys ships and Learniq's `DataExchangeJob`/`LearnerProfile` schemas do not yet model at all. `13.1`/`3.1` already cover the ROD contract's existence; this change is additive to that contract, not a new one — per `funding-and-teldatum-checks`'s own change-plan row: "Both are additive to the 13.1 ROD contract, not new contracts."

A count submitted on the wrong date, or without the school having verified its own numbers first, produces a funding error that takes months to correct through DUO's own process — the same category of risk `DataExchangeRunGuard`'s existing OSO gate and `privacy-governance-surfaces`'s partner-approval gate both address for their own targets: make the school confirm before the job is allowed to run, not after.

## Affected Projects
- [x] Project: `learniq` — `lib/Settings/learniq_register.json` (a `teldatumCheckDate`/`requiresTeldatumCheck`/`teldatumCheckStatus`/`teldatumCheckedBy`/`teldatumCheckedAt` property group on `DataExchangeJob`; a `fundingWeightCode` property on `LearnerProfile`), `lib/Lifecycle/DataExchangeRunGuard.php` (one more independent condition). No other project's files change; sending the count to DUO remains integriq's job (`integriq-adapter-rod`), unbuilt and out of scope here.

## Scope

### In Scope
- `DataExchangeJob` gains: `requiresTeldatumCheck` (boolean, default `false`), `teldatumCheckStatus` (enum `not-required | pending | confirmed`, default `not-required`), `teldatumCheckDate` (nullable date, the 1 February or 1 October count date this job's numbers are being verified against), `teldatumCheckedBy`, `teldatumCheckedAt` (both nullable). `DataExchangeRunGuard::check()` gains one more independent condition, in the exact shape `privacy-governance-surfaces`'s partner-approval condition already established: a job with `requiresTeldatumCheck === true` and `teldatumCheckStatus !== 'confirmed'` cannot reach `running`.
- `LearnerProfile` gains `fundingWeightCode` (nullable enum `noat | cumi | nnca`, default `null`) — the culturele-achtergrond funding-weging classification.
- Register-shape tests pinning both additions and the guard's new condition, including that it is independent of the existing OSO gate — each condition denies on its own, and none masks another. This change is built from `origin/development` independently of `privacy-governance-surfaces` (a sibling PR on the same guard file, not a dependency of this one); if that PR merges first, the two independent conditions compose without conflict — both follow the identical "opt-in boolean, own status enum" shape by design.

### Out of Scope
- Actually computing or comparing a real pupil count against DUO's records — that is integriq's adapter's job once built (`integriq-adapter-rod`), named as this change's own cross-repo dependency. `teldatumCheckStatus` is a school-confirmed manual attestation, not a computed verdict.
- Any UI beyond the generic object-edit form on the existing `DataExchangeJobDetail`/`LearnerProfileDetail` pages — no new manifest page, no new Vue component.
- A `DataExchangePartner`-style registry or any change to the partner-approval mechanism `privacy-governance-surfaces` adds — independent, unrelated condition on the same guard.

## Approach
Additive schema properties plus one more independent `if` condition on `DataExchangeRunGuard::check()`, mirroring the exact pattern `privacy-governance-surfaces` already used for its own partner-approval condition (same file, same shape, same "opt-in via a boolean, default `false`, never narrows an existing target" posture).

## New Dependencies
None.

## Impact
- `lib/Settings/learniq_register.json` — five new `DataExchangeJob` properties, one new `LearnerProfile` property.
- `lib/Lifecycle/DataExchangeRunGuard.php` — one additional, independent gating condition.
- No existing job or learner profile is affected: every new field defaults to `not-required`/`false`/`null`.

## Cross-Project Dependencies
Depends on integriq's `integriq-adapter-rod` (not yet built) to actually send a confirmed count to DUO — this change only adds the pre-flight confirmation gate and the funding-weight property; sending remains fully delegated, per D3.

## Risks

### Risk 1: A school could mark `teldatumCheckStatus: confirmed` without genuinely re-checking its counts
**Severity:** Low — **Mitigation:** this is a process control, not a technical one; the same limitation applies to ParnasSys's own teldatum-controles workflow, which is also a human confirmation step, not an automated verdict. Named explicitly rather than implied to be more than it is.

## Rollback Strategy
Revert `lib/Settings/learniq_register.json` and `lib/Lifecycle/DataExchangeRunGuard.php`. Every existing job defaults to `requiresTeldatumCheck: false`, so no job that runs today is affected by a rollback either.

## Open Questions
None.
