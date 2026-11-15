# Design: submission-resubmission-action

## Architecture Overview

```
SubmissionDetail (manifest)
  lifecycleActions.transitions[reopen] --inputs--> CnTransitionInputDialog (resubmissionDueAt)
        |
        v  POST /api/objects/{id}/transition { action: reopen, data: { resubmissionDueAt } }
  OpenRegister LifecycleValidationListener
        |-- authorization: instructors | compliance-officers | team-leads
        |-- writes declared input onto the Submission, lifecycle -> draft
        '-- notification resubmissionRequested -> learnerIds
Learner: submit -> SubmissionWindowGuard(deadline = resubmissionDueAt ?? Assignment.dueAt)
```

## Nextcloud Integration
- Register: `x-openregister-lifecycle` (inputs, authorization), `x-openregister-notifications`.
- Lifecycle guard: `OCA\Learniq\Lifecycle\SubmissionWindowGuard` (existing, extended).
- Frontend: `CnLifecycleActions` from `@conduction/nextcloud-vue` via manifest config; no new Vue.

## Decisions

### D1: The request carries its own date
Without it the button is useless after the deadline, which is when teachers mark. Alternatives
rejected: clearing the deadline for reopened work (no end to the resubmission) and a new
`resubmit` transition (the lifecycle already has `reopen`, and `submit` is the right verb for the
pupil). The input is required, so a request always has a date.

### D2: Declared transition list, not server-derived
`{ field: lifecycle }` alone would also show `return` on submitted work, bypassing the marking
screen that writes the grade. The manifest declares exactly one transition, `reopen`.

### D3: Per-transition authorization
Submission `update` RBAC already limits a `returned` row to staff, but the explicit
`authorization` makes the rule visible in the register and gives a clear refusal.

### D4: Guard override is local to `windowVerdict`
The guard passes the assignment with its `dueAt` replaced when `resubmissionDueAt` is set. The
window logic, the malformed-date refusal and the late rules stay one code path.

## Declarative-vs-imperative decision (ADR-031)
| Behaviour | Path | Rationale |
|---|---|---|
| Reopen with a date | declarative (`inputs` on the transition) | OpenRegister writes declared inputs. |
| Staff-only reopen | declarative (`authorization`) | Engine-enforced group list. |
| Notify the learners | declarative (`x-openregister-notifications`, trigger transition `reopen`) | Same shape as `ExcuseRequest.approved`. |
| Which deadline applies | imperative, existing guard | A guard is the ADR-031 exception for transition rules. |
| Only staff write the date | imperative pre-write listener | The register has no enforced per-field write rule (`x-property-rbac` is documentation only). ADR-031 exception: pre-write guard. |
| The button | declarative (`lifecycleActions`) | Manifest config. |

## Security Considerations
- Only staff can reopen (D3). A learner can still only hand in their own submission
  (`callerMayHandIn`, unchanged).
- `resubmissionDueAt` moves the deadline, so only staff may write it. A learner may create a
  Submission and update their own `draft` row, which would let them set their own date and hand in
  "on time" forever. `SubmissionResubmissionDateListener` closes that: on create and update by
  anyone outside `instructors`, `compliance-officers`, `team-leads` and admins, the value is
  stripped on create and restored to the stored one on update. System context (no user) passes.
  Same shape as `PortfolioEntryOwnershipListener`.

## Risks / Trade-offs
- [Pupil sees a button they cannot use] → server refusal, see proposal Risk 1.
- [Learners can still write other fields of their own draft, for example `proposedGrade`] →
  inherited, reported in the PR, not widened here.

## File Structure
```
lib/Settings/learniq_register.json            (Submission property, transition, notification)
lib/Lifecycle/SubmissionWindowGuard.php       (deadline override)
lib/Listener/SubmissionResubmissionDateListener.php (new: staff-only date)
lib/AppInfo/Registrar/IntegrityListenerRegistrar.php (two registrations)
src/manifest.d/learning.json                  (SubmissionDetail.lifecycleActions)
tests/Unit/Lifecycle/SubmissionWindowGuardTest.php
tests/Unit/Listener/SubmissionResubmissionDateListenerTest.php (new)
tests/Unit/Settings/SubmissionResubmissionRegisterTest.php (new)
l10n/en.json, l10n/nl.json (+ generated .js)
```

## Seed Data
`Submission` changes, so one existing seed Submission in `lib/Settings/learniq_mock_register.json`
that is in `draft` gains a `resubmissionDueAt`, showing a reopened hand-in.

## Trade-offs
The date is a plain transition input rendered by the library's input dialog with the property's
title and `date-time` format; no bespoke dialog.
