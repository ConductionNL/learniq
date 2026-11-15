---
kind: code
---

# Proposal: portal-assignment-hand-in-endpoint

## Summary
A pupil hands in a portal draft submission. Learniq adds one endpoint on the portal receiver of
`assessment-portal-endpoints` (learniq #1096): `POST /api/portal/submissions/hand-in`. It accepts
only portaliq's signed forward, takes the pupil from the `learnerRef` portaliq stamps, checks that
the submission is the pupil's own draft, and runs the `submit` or `submitLate` transition as the
pupil, so `SubmissionWindowGuard` decides the deadline and the late rule exactly as in the app. The
student manifest declares the matching `handIn` endpoint action, offered on the pupil's draft
submissions only.

## Motivation
- `assignment-portal-wiring` (learniq #1068), Out of Scope, verbatim: "Handing the portal draft in.
  The portal can create and attach, not fire `submit`/`submitLate`. Portaliq's `set` writes a field
  and would skip `SubmissionWindowGuard`, so it is not used. A hand-in endpoint action (the
  assertion receiver from `assessment-portal-endpoints`) is the follow-up."
- Measured on learniq `development` `0171a896`: `studentSubmissions` lists the pupil's submissions
  and `createSubmission` creates a draft with files, but no action moves a draft on. A portal pupil's
  work stays `draft` and the teacher never sees it handed in.
- Portaliq's generic status change (`type: update` with `set`) writes the `lifecycle` field through
  portaliq's writer with no Nextcloud user. `SubmissionWindowGuard` treats "no caller" as a system
  call and lets it through (`callerMayHandIn()`, `$userId === ''`), so the deadline and the late
  rule would not run. Learniq has to run the transition itself, as the pupil.
- Decision D15 (portal tests) set the pattern: learniq enforces every rule in its own portal
  endpoints, portaliq only forwards.

## Affected Projects
- [x] Project: `learniq`: the hand-in endpoint, a small hand-in service, the `handIn` action in the
  student manifest, two pupil messages.

## Scope

### In Scope
- `POST /api/portal/submissions/hand-in` on `PortalSubmissionController`, same order as
  `PortalAssessmentController`: assertion (401, throttled), audience `student` (403), `learnerRef`
  (403), pupil account (403 `not_available`), then the step.
- `PortalSubmissionHandIn`: read the submission, refuse one that is not the pupil's (404, one
  answer) or not a draft (409), ask `SubmissionWindowGuard` which hand-in applies (`submit` inside
  the window, `submitLate` after it when the assignment accepts late work), refuse when neither does
  (422 with the reason), and fire the transition as the pupil through OpenRegister's
  `TransitionEngine`, so the guard runs again on the write.
- `SubmissionWindowGuard` names its refusal texts as constants, so the endpoint can tell the late
  refusal from the others without matching a sentence.
- The `handIn` action in the student manifest: endpoint-forward, `fields: [submissionId]`,
  `subjectField: learnerRef`, and the row keys of portaliq `contribution-pay-screen` (#805):
  `rowField: submissionId`, `rowWhen: {field: lifecycle, in: [draft]}`, named in
  `studentSubmissions.rowActions`.

### Out of Scope
- Writing `submittedAt`: the app's own hand-in does not write it either; the transition is the
  record.
- The same hand-in for group work handed in by another member: the guard already allows any listed
  learner; nothing changes there.
- A button in portaliq before #805 lands: until then portaliq shows `handIn` as a plain action.

## Approach
Follow `assessment-portal-endpoints`: a thin receiver, a service that returns a `PortalOutcome`,
writes through `ObjectService::runAs()`. The guard stays the authority: the service asks it first
only to choose between `submit` and `submitLate` and to word a refusal, and the transition asks it
again. See design.md.

## New Dependencies
None.

## Impact
- New `lib/Controller/PortalSubmissionController.php`, `lib/Service/Portal/PortalSubmissionHandIn.php`.
- `lib/Lifecycle/SubmissionWindowGuard.php`: refusal texts as public constants (no behaviour change).
- `lib/Service/Portal/PortalMessages.php`: three hand-in reasons.
- `lib/Portal/PortalContributionProvider.php`: the `handIn` action and `studentSubmissions.rowActions`.
- `appinfo/routes.php`: one route. No schema change.

## Cross-Project Dependencies
- Portaliq's endpoint forward with `subjectField` (portaliq #749, merged) stamps `learnerRef`.
- Portaliq `contribution-pay-screen` (#805, open) turns the action into a per-row button on draft
  submissions and stamps `submissionId` from the row it read under the pupil's scope. Without it the
  action works as an id-addressed forward with `submissionId` in the body.

## Risks

### Risk 1: The deadline passes between the pre-check and the write
**Severity:** Low. **Mitigation:** the transition runs the guard again on save; its refusal is
caught and answered as the same 422 with the reason, and nothing is written.

### Risk 2: A pupil names another pupil's submission id
**Severity:** Medium. **Mitigation:** the submission must carry the pupil's `learnerRef` or list
their Nextcloud id in `learnerIds`; otherwise one 404, and the transition runs as the pupil, whom the
guard also refuses.

## Rollback Strategy
Revert the PR. No data changes; handed-in submissions stay handed in, as they would from the app.
