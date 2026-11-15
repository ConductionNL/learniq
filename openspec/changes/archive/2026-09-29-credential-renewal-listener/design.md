# Design: credential-renewal-listener

## Context

`Credential.renewalEnrolmentId` names a write path ("Written back by OR
batch") that does not exist. The four expiry-adjacent notifications are all
correctly wired; only the side effect of *acting* on an expiry (creating a
renewal Enrolment) is missing. `ExemptionGrantHandler` is the direct precedent
for "a lifecycle transition on schema A creates and links a new object on
schema B" in this register.

## Goals / Non-Goals

**Goals:**
- On `Credential.expire`, create a renewal `Enrolment` and link it back.

**Non-Goals:**
- Content-version-change-triggered renewal (named explicitly as a separate,
  larger feature — no content-version concept exists on `Course` today).
- Deduplicating against an Enrolment the learner may already hold for the
  same course.

## Declarative-vs-imperative decision (ADR-031)

Legitimate imperative exception: "create a sibling object and link it back"
has no declarative primitive in this register's calc/notification/aggregation
dialect (confirmed by `ExemptionGrantHandler`'s own docblock, which names the
identical class of exception for its ExemptionCase → GradeEntry bridge). A
declarative `x-openregister-notifications` rule can send a *notification* on
expiry (already done, unchanged by this fix) but cannot *create a new object*.

## Decisions

### Decision 1: Copy learnerId/courseId/regulationSlug only, no dueDate

The new Enrolment carries exactly the fields the expiring Credential can
supply unambiguously (`learnerId`, `courseId`, `regulationSlug`). No
`dueDate` is set (left null, "no deadline") — the credential's own
`expiresAt` already documents when the *old* credential lapsed; inventing a
new deadline for the renewal course without a stated business rule for what
that deadline should be would be a guess, not a fact carried over from the
source object.

### Decision 2: `mandatory: true`

A credential-tracked course is, by definition, compliance-relevant (the
`Credential` schema exists specifically for regulation-tracked
training/certification per this register's AVG Art. 30 catalogue). The
renewal Enrolment inherits that compliance posture.

### Decision 3: Add `credential-renewal` to `Enrolment.source`'s enum

Mirrors the register's own established pattern: `admission` and
`subject-choice` were each added to this same enum with a docstring
attribution when their respective creating bridges (`ApplicationConversionHandler`,
`SubjectChoiceEnrolmentBridge`) were built. Reusing an existing value like
`system` would erase the specific, auditable provenance a compliance-tracked
renewal needs.

### Decision 4: No use of `TransitionEngine` — the new Enrolment starts at its own initial state

Unlike `ExemptionGrantHandler` (which drives its created GradeEntry through an
*existing* `publish` transition so the standard notification/audit path
fires), a newly created Enrolment needs no transition — `pending` is already
its correct initial lifecycle value per `x-openregister-lifecycle.initial`,
and Enrolment's own existing notifications/guards apply to it exactly as they
would to any other freshly created Enrolment. `ObjectService::saveObject()`
alone is sufficient, mirroring `SubjectChoiceEnrolmentBridge`'s plain-create
shape rather than `ExemptionGrantHandler`'s create-then-transition shape.

## Risks / Trade-offs

- [Risk] No dedup against an existing Enrolment for the same learner/course →
  Mitigation: named as a follow-up in the proposal; current behaviour (no
  renewal at all) is strictly worse.

## Migration Plan

None — a new listener class, one enum value addition (additive, not a
breaking change to the enum), one registration line.

## Open Questions

None.
