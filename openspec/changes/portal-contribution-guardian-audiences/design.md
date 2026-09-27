# Design: portal-contribution-guardian-audiences

## Context
`PortalContributionProvider::parentContribution()` (shipped by `portal-parent`) already reads four collections
reverse-joined via `guardianRefs`, and explicitly defers its create action pending portaliq's writer
cross-reference guard. That guard is now merged (`portaliq#607`, 2026-09-18, commit `809fde2`): "a declared cross
reference must resolve inside the subject's own scope." This change is the direct continuation `portal-parent`'s own
docblock named: "re-add here once that lands."

## Goals / Non-Goals
**Goals:** per-child and per-guardian-group directory data; per-purpose beeldmateriaal consent, staff-resolved across
guardians; re-enable the guardian create action now that its blocking guard exists.

**Non-Goals:** a school-class "group" audience (two-hop join, portaliq contract limitation, named as a follow-up);
per-guardian individual consent votes (a richer schema, deferred); verlof/consent as their own schemas (10.6's own
scope note).

## Decisions

### Decision 1: `parentChildren` matches directly, not via a reverse `via` join
`guardianRefs` is an array-valued property ON `learner-profile` itself. `studentActivityCollections()`'s
`Submission` collection already demonstrates the DIRECT array-containment match (`scopeField: 'learnerRefs'`, no
`via`) for exactly this shape — a scope field that is an array on the SAME schema being read, checked for
containing the resolved claim value. `parentChildren` reuses that precedent rather than inventing a new mechanism:
no `via` is declared, because there is no cross-object hop — the guardian is looking for `learner-profile` rows
where they themselves appear in the row's own `guardianRefs`.

### Decision 2: `groupByField` on the four existing collections, not four new collections
Adding a `groupByField: 'learnerRef'` hint is additive metadata on already-shipped collections — a portal client
groups the existing flat result set per child without Learniq declaring four times as many collections. This keeps
the "per-child audience" delivery to one line per collection.

### Decision 3: The create action's `scopeField` is `submittedByRef`, never `learnerRef`
This is the load-bearing correctness decision, and the one Risk 2 names directly. `learnerRef` identifies WHICH
CHILD an excuse concerns; `submittedByRef` identifies WHO FILED it. A guardian's create body supplies `learnerRef`
as a genuine cross-reference (validated by `portaliq#607` against the `via`-derived scope), while the writer
server-stamps `submittedByRef` to the guardian's own resolved UUID — exactly the shape `portal-parent`'s own
deferral comment described: "the create-action stamps the guardian UUID into `submittedByRef` (never `learnerRef`,
which is the child)." This change does not change that plan; it only removes the dependency that blocked shipping
it.

### Decision 4: `beeldmateriaalConsent` is a combined, staff-maintained record — not a per-guardian vote log
Building a `{guardianRef, purpose, consent}` sub-schema and a computed "AND across guardians" resolution would need
either a new nested collection or a materialized calculation aggregating across a separate schema — a genuinely
bigger change than this one's "M" size budget. The combined record (one boolean per purpose) is exactly what a
`LearnerProfileDetail` staff view can already edit through the generic object-edit form; the multi-guardian
resolution is a process staff already have to run when a second guardian's answer differs (the same posture
`funding-and-teldatum-checks` took for its own teldatum check being a human attestation, not a computed one).

## Declarative-vs-imperative decision (ADR-031)
Everything in this change is declarative: two new `LearnerProfile` properties (plain data) and manifest-shaped
array construction inside `PortalContributionProvider` (already a pure, I/O-free class per its own docblock — this
change adds more of the same, no new imperative logic). No guard, no controller, no new PHP class.

## Seed Data (ADR-001)
No new seed objects. Existing `LearnerProfile` objects gain the two new properties at their additive defaults
(`beeldmateriaalConsent`'s five sub-fields default `null`; `beeldmateriaalConsentReviewDueAt` defaults `null`),
requiring no backfill.

## Risks / Trade-offs
- [Risk] see proposal Risk 1 (multi-guardian resolution is a process control) and Risk 2 (a wrong `scopeField`
  would reproduce a write IDOR) → both mitigated as described there; Risk 2's mitigation is a hard drift-pin test,
  not a comment.

## Migration Plan
No Nextcloud migration class. Rollback: revert `lib/Settings/learniq_register.json` and
`lib/Portal/PortalContributionProvider.php`; `parentActions()` reverting to `[]` restores exactly `portal-parent`'s
shipped state.

## Open Questions
Named in the proposal: per-guardian vote tracking, and a genuine school-class group audience (a portaliq contract
question, not this change's to answer).
