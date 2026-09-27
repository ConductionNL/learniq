---
kind: code
---

# Proposal: assessment-portal-endpoints

## Summary
A pupil takes a timed test through the portal. Learniq adds the five endpoints portaliq's `timedTask`
block names (`available`, `start`, `answer`, `submit`, `result`), keyed by the `learnerRef` portaliq
stamps into the forwarded body through `subjectField`. Every test rule runs inside these endpoints:
the availability window and release conditions, the access code, extra time from an approved
`ExamAccommodation`, one attempt unless retakes are allowed, answers saved per question, nothing
after hand-in, auto scoring of closed items, and the result only once the teacher released it.

## Motivation
Round 2 recon C (`learniq-mi/learniq/_round2/recon/C-tests-grading-assignments.md`, section 1):
"Taking a test via portaliq does not exist today, full stop." Decision D15 builds it in wave 2 as
its own change. Portaliq shipped its half in ConductionNL/portaliq#749: a `timedTask` collection
that drives five endpoint actions through the signed forward, and a design that names the seven
things learniq has to add.

Portal requests carry no Nextcloud user. Learniq's existing guards treat that as system context:
`AssessmentAttemptGateListener::callerBypasses()` returns true without a user, and
`AssessmentResultIntegrityListener::policedUser()` returns null. A portal path that wrote attempts
without a user would skip the window, the access code and the immutability rules. So the rules
have to run in the endpoints themselves. Two rules have no server enforcement at all today, in the
app either: `maxAttempts` and the time limit.

Capability rows (round 1 `compare/M1-rows-draft.md`): 6.4 "Online assessments with proctoring and
accommodations" and 7.13. Competitor evidence, recon C section 2: Woots extra-time column and
auto-marked multiple choice (`assessment/round1/notes-woots.txt`), Moodle quiz per-user time and
attempt overrides (`moodle/round1/M1-moodle-column.md`). Rung 2: new endpoints behind an existing
portal surface, no learniq page.

## Affected Projects
- [x] Project: `learniq`: an `X-Portal-Subject` receiver (`PortalAssertionVerifier`), a portal
  assessment controller with five routes and its services, `AssessmentResult.learnerRef` and
  `assessmentTitle`, a fix so auto scoring reads the stored answer shape, and the `studentTests`
  timed task in the portal contribution.

## Scope

### In Scope
- `PortalAssertionVerifier`: portaliq's HS256 assertion, verified the way filinq's and shillinq's
  receivers do, with portaliq's current secret rule (the dedicated `jwt_signing_secret` only).
- Five POST routes under `/apps/learniq/api/portal/assessments`, each verifying the assertion,
  requiring audience `student` and taking the learner only from the stamped `learnerRef`.
- The rules listed in the summary, each enforced in the endpoints, with a test per rule.
- Writes run as the pupil's own Nextcloud account (`ObjectService::runAs()`), so the attempt gate,
  the integrity listener and RBAC also apply. A profile whose account does not exist is refused.
- `AssessmentResult.learnerRef` and `assessmentTitle`, stamped on every create by the attempt gate.
- Auto scoring reads `responses[].response.value`, the shape the app and the portal both store.
- The `studentTests` collection (`kind: timedTask`) and five endpoint actions with `subjectField:
  learnerRef` in the student contribution.

### Out of Scope
- Proctored tests in the portal. The portal has no test-mode hardening, so a test with a
  `proctoring` configuration is not offered there.
- Offline answering (portaliq keeps unsaved answers visible and retries).
- `maxAttempts` and the time limit in the app's own `TakeAssessmentView`: named as a finding.
- A hand-in action for portal assignment drafts (the receiver added here makes it a small follow-up).

## Approach
A thin controller verifies and delegates. Services hold the rules: a catalogue decides what a
pupil may start, an attempt service starts, saves and hands in, a result reader applies the release
rule, and two pure helpers compute the deadline and turn QTI into the portal's item shape. Reads run
without RBAC and filter by the learner explicitly; writes run as the learner. Details in design.md,
the wire contract in contract.md.

## New Dependencies
None.

## Impact
- New: `lib/Portal/PortalAssertionVerifier.php`, `lib/Controller/PortalAssessmentController.php`,
  services under `lib/Service/Portal/`.
- Changed: `appinfo/routes.php`, `lib/Portal/PortalContributionProvider.php`,
  `lib/Listener/AssessmentAttemptGateListener.php`, `lib/Lifecycle/AssessmentScoringHandler.php`,
  `lib/Settings/learniq_register.json` (AssessmentResult 0.1.0 to 0.2.0), test stubs.

## Cross-Project Dependencies
- Consumes ConductionNL/portaliq#749 (`timedTask`, `subjectField`). Without #749 portaliq drops the
  unknown block fail-closed and shows the attempts as a plain list.
- Mirrors portaliq's `PortalJwtService` assertion format and `PortalSessionService` secret rule.

## Risks

### Risk 1: a pupil without a Nextcloud account cannot take a portal test
**Severity:** Medium. **Mitigation:** acting as the pupil is what makes the existing guards and RBAC
apply. Elevating to a system principal for an inbound request is what OpenRegister's `runAsSystem()`
forbids (ADR-099). Every LearnerProfile carries a required `ncUserId`; a profile whose account does
not exist gets a clear refusal.

### Risk 2: auto scoring depends on reads fixed in PR #1047
**Severity:** Medium. **Mitigation:** `AssessmentScoringHandler` passes `register`/`schema` outside
`filters`, which OpenRegister ignores; #1047 fixes that repo-wide and this change stays off those
lines. Until #1047 lands, a submit may be refused by the scoring guard, and the endpoint reports it
as a downstream error instead of claiming success.

### Risk 3: the scoring fix changes scores on existing attempts only when they are re-scored
**Severity:** Low. **Mitigation:** scores are written once, on `submit`. Past attempts keep the zero
the old comparison gave; teachers can still score them by hand.

## Rollback Strategy
Revert the PR. The routes disappear, portaliq falls back to the plain attempts list, and the extra
AssessmentResult properties are ignored by the previous schema.
