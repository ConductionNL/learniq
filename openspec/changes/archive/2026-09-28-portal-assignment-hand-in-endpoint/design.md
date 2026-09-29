# Design: portal-assignment-hand-in-endpoint

Read at learniq `development` `0171a896` (#1068 and #1096 merged) and openregister `development`
`9c378221d6`.

## Architecture overview

```
portaliq (row button on a draft in studentSubmissions, or the id-addressed forward)
  -> POST /apps/learniq/api/portal/submissions/hand-in
       X-Portal-Subject: <assertion>      body: {submissionId, learnerRef (stamped by portaliq)}
     PortalSubmissionController::handIn()
       verify (401, throttled) -> audience student (403) -> learnerRef (403)
       -> PortalLearnerResolver (403 not_available)
       -> PortalSubmissionHandIn::handIn(learner, submissionId)
            read Submission (RBAC off)            -> 404 not_found when missing or not the pupil's
            lifecycle draft                       -> 409 already_handed_in
            SubmissionWindowGuard::check(submit)  -> allowed: submit
            SubmissionWindowGuard::check(submitLate) -> allowed: submitLate
            neither                               -> 422 late_not_accepted | hand_in_refused
            runAs(pupil) TransitionEngine::transition(id, action)
              (LifecycleValidationListener runs the guard again on the save)
            HookStoppedException lifecycle-guard-denied -> the same 422
       <- 200 {submissionId, lifecycle}
```

## Decisions

### D1. A second receiver controller on the same pattern

`PortalSubmissionController` repeats the short verify-and-resolve preamble of
`PortalAssessmentController` rather than sharing it. The assessment receiver is merged and tested;
folding both behind one helper changes its constructor and its tests for a 30-line gain. The two
stay identical in order and answers; the test of each pins it.

### D2. The guard picks the transition and is asked again on the write

The app picks `submit` or `submitLate` in the browser (`handInAction()` in `src/utils/customPages.js`)
and lets the guard refuse. On the server the service asks the guard itself, first for `submit`, then
for `submitLate`, with the pupil's uid, so the window, the resubmission date and the late rule are
the guard's code and nobody else's. It then fires the allowed transition as the pupil. OpenRegister's
`LifecycleValidationListener` runs the guard again on that save, so a deadline that passes in
between is refused there; the `HookStoppedException` it throws (code `lifecycle-guard-denied`) is
caught and answered as 422.

Alternative considered: compute the window in the service. Rejected: it would copy the guard's
deadline rule, which already has one subtle override (`resubmissionDueAt`).

### D3. Refusal reasons without matching sentences

`SubmissionWindowGuard` keeps its texts but names them as public constants
(`DENY_LATE_NOT_ACCEPTED` and the others). The service maps the `submitLate` verdict's message:
`DENY_LATE_NOT_ACCEPTED` becomes `late_not_accepted`, anything else `hand_in_refused`. When both
verdicts deny, the `submitLate` message is always the one that explains it (the guard answers the
same for ownership, a missing assignment and an unreadable deadline on both transitions).

### D4. Ownership before the guard

The service reads the submission with RBAC off (the receiver has no session) and requires the
pupil's `learnerRef` on it or their Nextcloud id in `learnerIds`. A foreign id and a missing id get
the same 404. The guard's own `learnerIds` check then runs as the pupil too.

### D5. The action is a row action where portaliq supports it

`handIn` carries `rowField` and `rowWhen` (portaliq #805). A portaliq with #805 shows a hand-in
button on draft rows only and stamps the row id it read under `learnerRef`; an older portaliq ignores
the two keys and offers an id-addressed forward whose `submissionId` comes from the body. Both reach
the same endpoint, which checks ownership itself.

## Declarative-vs-imperative decision (ADR-031)

| Behaviour | Path | Why |
|---|---|---|
| The hand-in rules (window, late, ownership) | Existing lifecycle guard | Unchanged; the guard is the declared `requires` of both transitions |
| The receiver | Imperative controller and service | External integration: a signed server-to-server forward with no session (ADR-031 exception, as `assessment-portal-endpoints`) |

## API design

`POST /apps/learniq/api/portal/submissions/hand-in`

| Status | Body |
|---|---|
| 200 | `{submissionId, lifecycle: submitted \| late}` |
| 401 | `{error: unauthorized}` (throttled) |
| 403 | `{error: forbidden}`; `{error: not_available, message}` |
| 404 | `{error: not_found, message}` |
| 409 | `{error: already_handed_in, message}` |
| 422 | `{error: late_not_accepted \| hand_in_refused, message}` |
| 502 | `{error: downstream_error}` |

## Database changes

None.

## Security considerations

- The assertion is the only credential; no session fallback; failed verification throttled.
- The pupil comes only from the stamped `learnerRef`; the submission id is checked against it.
- The write runs as the pupil, so OpenRegister's own `update` check on the object and the guard's
  learner check apply as in the app.
- No internals in a 502.

## Seed data

No schema change. Tests build synthetic submissions and assignments in the existing
`PortalFakeRegister` style.
