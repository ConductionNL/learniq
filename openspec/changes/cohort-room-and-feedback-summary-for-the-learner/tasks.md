# Tasks: cohort-room-and-feedback-summary-for-the-learner

## 1. Cohort Talk room

- [ ] 1.1 Create and link a Talk room when a Cohort becomes active with no room linked: a listener next to `lib/Listener/CohortTalkMembershipHandler.php`, using OpenRegister's `TalkLinkService`. Skip quietly when Talk is not available.
  - PHPUnit: a new active cohort gets one linked room; a cohort with a room gets no second one; Talk off creates nothing.

## 2. Feedback summary for the learner

- [ ] 2.1 Add the learner read rule to `PeerFeedbackSummary` in `lib/Settings/learniq_register.json`: the submission's own learner reads it once a peer review is released.
- [ ] 2.2 Leave reviewer identity out of what the learner reads (`lib/Listener/PeerFeedbackAggregator.php` writes no reviewer ids into the summary, or the learner read hides them).
- [ ] 2.3 Show the summary on the learner's submission page (`LqLlCijfers` board).
  - PHPUnit: the learner reads their own summary and not another learner's; no reviewer name or id is in the answer.

## 3. Close out

- [ ] 3.1 Set both rows to `built` in `openspec/parity/capabilities.json` with the code as evidence, and archive this change.
