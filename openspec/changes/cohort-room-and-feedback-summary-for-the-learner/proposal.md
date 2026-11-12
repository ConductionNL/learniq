---
kind: code
---

# A new cohort gets its chat room, and a learner reads their own feedback summary

## Why

Ruben decided two capability rows on 12 Nov 2026 (decision 392). Both are not built yet.

- `enr-cohort-gets-a-talk-room`: a new cohort gets its own Talk room, created and linked by itself. Today a coordinator creates and links the room by hand on the Cohort detail page, and only membership sync is automatic (`CohortTalkMembershipHandler`).
- `asg-feedback-summary`: a learner reads the one summary of the feedback on their own submission. Reviewers stay anonymous in it. Today `PeerFeedbackSummary` has no read rule for the learner, so only staff see it.

## What changes

- When a Cohort becomes active with no Talk room linked, Learniq creates a Talk room named after the cohort and links it through OpenRegister's Talk link. A coordinator can still link a room by hand when Talk was not available at that moment.
- `PeerFeedbackSummary` gets a learner read rule: the submission's own learner reads it once at least one peer review is released. The learner's view carries no reviewer identity.

## Matrix rows (`openspec/parity/capabilities.json`)

| row | spec | requirement |
|---|---|---|
| `enr-cohort-gets-a-talk-room` | `school-structure` | A new cohort gets its own Talk room automatically |
| `asg-feedback-summary` | `assignments` | A learner reads the feedback summary of their own submission with reviewers anonymised |
