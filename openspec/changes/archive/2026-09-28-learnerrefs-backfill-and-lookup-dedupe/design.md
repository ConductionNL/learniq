# Design: learnerrefs-backfill-and-lookup-dedupe

## Decisions

### D1. Fold without changing a read

`LearnerProfileLookup::refForUser()` read with `_multitenancy: false`; `LearnerRefResolver::resolve()` reads with OpenRegister's default (scoped). Merging them into one scoped or one unscoped method would change what some caller sees. So the resolver keeps `resolve()` as it is and gains `resolveAcrossTenants()` and `byRef()`, which are the portal half verbatim. Two named methods instead of a boolean flag, which phpmd rejects and which reads worse at the call site.

### D2. Delete the class once its last caller moves

#1129 (portal absence reports stamped on the server) added a caller of `LearnerProfileLookup` while this change was open. Until it landed the class stayed as a one-line facade over the resolver, so neither PR broke the other. #1129 landed first, so the catch-up merge moved `ExcuseRequestOwnerStamp` to the resolver and deleted the class.

### D3. The back-fill derives what the stamps derive

`learnerRefs` follows `SubmissionLearnerRefsStamp` (every learner with a profile, in order); `learnerRef` follows `SubmissionOwnerStamp` (the first learner's profile, or null). The step compares derived with stored and saves only differences, which makes it idempotent and corrects a stale value to what the next ordinary save would write. Lookups read across tenants: a repair step has no session. Registered after `InitializeSettings`, next to `BackfillGradeEntryLearnerRef`, with a test that checks the registration so the step cannot pass its suite and never run.

## Declarative-vs-imperative decision (ADR-031)

The back-fill is a one-time data repair (an `IRepairStep`), not a calculation; the resolver is an existing ADR-031 exception (cross-schema lookup on a non-key field).

## Security Considerations

The portal lookups keep reading across tenants as before; the repair step runs without a session, as every repair step does. No new endpoint.

## Seed Data

No schema change.
