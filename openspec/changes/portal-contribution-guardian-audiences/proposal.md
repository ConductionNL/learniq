---
kind: code
depends_on: [portal-parent]
---

# Proposal: portal-contribution-guardian-audiences

## Summary
This is the **one** learniq change D1 allows beyond the audiences/consent state it already contributes: closing findings **2.8** (beeldmateriaal consent per purpose, withdrawable), **PA-new-2** (multi-guardian consent resolution), **4.5**/**9.13** (parent reports a child sick), and **10.6** (guardian creates records scoped to their own children). `PortalContributionProvider::parentContribution()` already ships four reverse-joined read collections (grades, attendance, excuse requests, report cards) but merges every child into one undifferentiated set and ships zero create actions. This change adds a `parentChildren` directory collection (per-child, per-group/co-guardian, and per-purpose consent state in one read), a `groupByField` hint on the four existing collections so a portal can render per-child sections, and re-enables `parentActions()` with `createExcuseRequest` — now that portaliq's writer cross-reference guard is merged (`portaliq#607`, commit `809fde2`, "a declared cross reference must resolve inside the subject's own scope"), the exact guard `portal-parent`'s own deferral was waiting on.

## Motivation
Every competitor named against 4.5/9.13/10.6 (aula, wilma, edupage, iserv, social-schools) ships the SAME shape: a guardian reports a child's absence from the parent app, landing directly in the school's record — `PortalContributionProvider`'s own `parentContribution()` docblock has documented this exact gap since `portal-parent` shipped, with an explicit "re-add here once that lands" note pointing at the guard this change now relies on. `2.8`/`PA-new-2` (aula/social-schools/kwieb/parnassys/magister) all model beeldmateriaal consent **per purpose** (schoolgids, website, social media, class photo, video) and **per guardian**, with Kwieb explicit that one guardian's refusal is decisive ("op ieder moment de Toestemmingen wijzigen") — Learniq has only a single generic `SubjectChoice.guardianConsentGiven` boolean today, nowhere near this shape.

## Affected Projects
- [x] Project: `learniq` — `lib/Settings/learniq_register.json` (two additive `LearnerProfile` properties: `beeldmateriaalConsent`, `beeldmateriaalConsentReviewDueAt`), `lib/Portal/PortalContributionProvider.php` (new `parentChildren` collection, `groupByField` on the four existing parent collections, re-enabled `parentActions()`), `tests/Unit/Portal/PortalContributionProviderTest.php`, `tests/Unit/Settings/GuardianAudienceRegisterTest.php`. No route, no controller, no frontend view — the portal itself is portaliq's, per ADR-046.

## Scope

### In Scope
- `LearnerProfile.beeldmateriaalConsent`: an object with five nullable-boolean sub-fields (`website`, `socialMedia`, `schoolgids`, `classPhoto`, `video`) — the school's current, combined-per-purpose record. **Multi-guardian resolution is a staff process, not a computed verdict**: when a second guardian refuses a purpose already granted, staff flip that purpose to `false` — the same "human attestation, not an automated comparison" posture `funding-and-teldatum-checks` already took for its own teldatum check, named explicitly rather than implied to be more. `LearnerProfile.beeldmateriaalConsentReviewDueAt` (nullable date) backs the yearly-reminder pattern `PA-new-3`/Kwieb document (reminder delivery itself is portaliq's job, out of scope here — this is the date field a future portaliq notification would read).
- A new parent-audience read collection, `parentChildren`: `learner-profile` rows matched **directly** (no `via` — `guardianRefs` is an array-valued field ON `learner-profile` itself, matched the same way `studentActivityCollections()`'s `Submission.learnerRefs` already is, per its own precedent), scoped by `guardianRefs` containing the guardian's `subjectRef`. Fields: `givenName`, `familyName`, `guardianRefs` (the full co-guardian group — the "per-group" audience: which other guardians share this child), `beeldmateriaalConsent`, `beeldmateriaalConsentReviewDueAt`. This is the one collection a portal needs to build a per-child, per-guardian-group navigation and to show current consent state per purpose.
- `groupByField: 'learnerRef'` added to the four existing parent read collections (`parentGrades`, `parentAttendance`, `parentExcuseRequests`, `parentReportCards`) — a portal can now render "my child A's grades" separately from "my child B's grades" from the same reverse-joined set, closing the "per-child audience" half of D1's contribution list.
- `parentActions()` re-enabled with `createExcuseRequest`: `scopeField: submittedByRef`, `scopeClaim: guardianRef`, `via: $childJoin` (the SAME reverse-join descriptor the read collections already use). Per `portaliq#607`, the writer now validates that a client-supplied cross-reference (`learnerRef`, in `fields`) resolves inside the subject's own `via`-derived scope — the exact guard `portal-parent`'s deferral comment named as the blocker. `minTrust: substantial`, matching every other parent action/read.
- Belt-and-braces unit tests (learniq side, per the orchestrator's explicit instruction): a drift-pin asserting `createExcuseRequest`'s `via` is byte-identical to the read collections' `$childJoin`, and that `fields` includes `learnerRef` (the cross-ref `portaliq#607` validates) while `scopeField` is `submittedByRef` (never `learnerRef` — the exact IDOR shape a wrong `scopeField` would create, since stamping `learnerRef` from `guardianRef` would silently write the guardian's own UUID into the child-identifying field).

### Out of Scope
- "Verlof" and "consent" as their own schemas — finding 10.6 itself states "verlof and consent are not modelled as schemas at all"; this change closes the sick-report/excuse-request path only, per 4.5/9.13's own scope.
- Per-guardian raw consent votes (a `{guardianRef, purpose, consent}` sub-schema) — the combined, staff-maintained record is this change's scope; a richer per-guardian audit trail is a named follow-up (Open Questions).
- Any cohort/class "group" audience (a school-class-level messaging target) — the `via` contract's documented shape is a single-hop reverse join (`portal-parent`'s design.md); a class-level audience would need a second hop (guardian → child → Cohort) the contract does not yet support. "Per-group" in this change means the co-guardian group sharing one child, not a school class — named explicitly so the two are not conflated (see Open Questions).
- Any change to `studentContribution()`, `practicalTrainerContribution()`, or `externalAssessorContribution()`.
- The actual yearly-reminder delivery, or any guardian-facing consent-CHANGE workflow (D1: new notification/messaging surfaces are portaliq's).

## Approach
Two additive `LearnerProfile` properties plus a `PortalContributionProvider.php` change entirely within the existing declarative-manifest pattern (no I/O in the provider, per its own class docblock) — consuming `portaliq#607`'s now-merged writer guard rather than building a new one.

## New Dependencies
None new. Depends at runtime on portaliq's merged reverse-join reader (contract v2.2, already consumed by `portal-parent`) and its now-also-merged create-body cross-reference validation (`portaliq#607`).

## Impact
- `lib/Settings/learniq_register.json` — two new `LearnerProfile` properties, both nullable/defaulted.
- `lib/Portal/PortalContributionProvider.php` — one new read collection, `groupByField` on four existing collections, `parentActions()` populated.
- `openspec/specs/portal-contribution/spec.md` stays `in-progress`.

## Cross-Project Dependencies
Depends on **portaliq `portal-writer-crossref-guard`**, confirmed merged 2026-09-18 (`portaliq#607`, commit `809fde2`, "a declared cross reference must resolve inside the subject's own scope") — this is exactly the guard `portal-parent`'s own deferral comment named as the blocker for shipping a guardian create action. Without it a guardian create would be a write IDOR (a guardian filing an excuse on another child's record); with it, the `via`-declared cross-reference on the create action is validated the same way the read collections' `via` already is.

## Risks

### Risk 1: `beeldmateriaalConsent`'s multi-guardian resolution depends on staff correctly re-checking on a second guardian's refusal
**Severity:** Medium — **Mitigation:** named explicitly in Scope and here, not implied to be more than a process control. A richer per-guardian vote trail (Open Questions) would close this at the cost of a bigger schema and a computed-resolution mechanism this change's size does not carry.

### Risk 2: A wrong `scopeField` on the create action would silently reproduce the write-IDOR `portal-parent` deferred
**Severity:** High — **Mitigation:** the drift-pin test asserts `scopeField` is exactly `submittedByRef` (never `learnerRef`) and that `via` is byte-identical to the read collections' `$childJoin` — a regression here fails the suite, not production.

## Rollback Strategy
Revert `lib/Settings/learniq_register.json` and `lib/Portal/PortalContributionProvider.php`. `parentActions()` reverting to `[]` restores exactly `portal-parent`'s shipped (reads-only) state; no data migration needed for the two additive `LearnerProfile` properties.

## Open Questions
- Whether a future change should track per-guardian consent votes individually (closing Risk 1 at the cost of a richer schema) — deferred; the combined record is this change's deliberate scope.
- Whether a genuine school-class "group" audience (requiring a two-hop `via` contract extension) is worth raising with portaliq as a follow-up contract amendment — named, not raised here.
