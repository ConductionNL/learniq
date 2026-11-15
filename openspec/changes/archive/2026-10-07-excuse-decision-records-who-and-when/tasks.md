# Tasks: an absence decision records who decided and when

- [x] 1.1 Declare `StampTransitionActorAction` (decidedBy, decidedAt) on `approve` and `reject`. Verify: PHPUnit `ExcuseRequestRegisterTest::testADecisionStampsTheDeciderAndTheTime` (red before, green after).
- [x] 1.2 Live: the teacher approves a portal report; the guardian's list shows the decision date. Verify: `tests/e2e/po-parent-flows.spec.ts`.
