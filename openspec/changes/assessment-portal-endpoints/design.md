# Design: assessment-portal-endpoints

## Architecture Overview

```
portaliq (TimedTaskView -> ContributionController::action, #749)
   POST /apps/learniq/api/portal/assessments[/start|/answer|/submit|/result]
   headers: X-Portal-Subject (60 s HS256 assertion), body: whitelisted fields + learnerRef (subjectField)
        |
learniq PortalAssessmentController   verify assertion (401) -> audience student (403) -> learnerRef (403)
        |                            -> PortalLearnerResolver: LearnerProfile + the pupil's IUser (403)
        v
   PortalAssessmentCatalogue   what this pupil may start (enrolment, published, not proctored,
        |                      LessonReleaseEvaluator, attempts left, attempt in progress)
   PortalAttemptService        start / answer / submit, deadline via PortalAttemptClock,
        |                      items via PortalItemPresenter
   PortalResultReader          the release rule and the result payload
        |
   PortalAttemptStore          reads: RBAC off, filtered by the pupil explicitly
                               writes: ObjectService::runAs(pupil) -> saveObject / TransitionEngine
                                 -> AssessmentAttemptGateListener (window, code, audience, portal stamp)
                                 -> AssessmentDrawResolver (drawnItemRefs)
                                 -> AssessmentResultIntegrityListener (immutability)
                                 -> submit: AssessmentScoringHandler + AssessmentAutoScoreAction
```

## Decisions

### D1: The receiver pattern the merged portal contributions use
Portaliq's forward is a server-to-server call with no Nextcloud credentials. Nextcloud's
`SecurityMiddleware` refuses such a request before the controller unless the method is
`#[PublicPage]`, which is why filinq's `PortalSigningReceiverController` and shillinq's
`PortalPaymentInitiationController` both carry `#[PublicPage]`, `#[NoCSRFRequired]` and an
`#[AnonRateLimit]`. The attribute only lifts the session requirement: the assertion is the only
credential, verified first in every method, with no session fallback. A failed verification
throttles the caller (`#[BruteForceProtection]`, as filinq does). An endpoint that is public in the
plain sense (readable without the assertion) is exactly what this rules out.

### D2: The verifier follows portaliq's current secret rule
`PortalAssertionVerifier` is a copy of the fleet receiver (hand-rolled HS256, `hash_equals`, the same
fail-closed checks). Filinq's and shillinq's copies still fall back to the instance secret when the
dedicated secret is short. Portaliq itself no longer does: `PortalSessionService::__construct()`
builds the minter from `jwt_signing_secret` only and refuses to mint otherwise. Learniq accepts only
what portaliq can mint, so it reads the dedicated secret only.

### D3: Rules in the endpoints, listeners as the second layer
Every rule is checked in the services before a write, with its own response code. The writes then
run as the pupil (`ObjectService::runAs()`), which narrows rather than elevates: the attempt gate
re-checks the window and the code, the integrity listener freezes a handed-in attempt, and RBAC
applies as for the pupil in the app. `runAsSystem()` is not used: OpenRegister forbids it for the
handling of an inbound request (ADR-099), and a missing identity is a refusal, not an escalation.

### D4: Reads without RBAC, filtered by the pupil
The test is read raw because its access code is write-only; items are read raw because a pupil must
not receive `correctResponse`. Attempts, enrolments and accommodations are read with explicit
`learnerId` filters, with `register` and `schema` nested under `filters`, the only place
`ObjectService::prepareFindAllConfig()` reads them. An attempt that is not the pupil's answers 404,
so the endpoints are no oracle for other pupils' attempts.

### D5: The deadline is the server's and includes extra time
`PortalAttemptClock` is pure: `deadline = startedAt + timeLimitMinutes × (1 + p / 100)`. `p` comes
from the pupil's approved or active `extra-time-percentage` accommodations: one for this test wins
over a generic one; among several of one kind the largest applies; `p` is clamped to 0 to 300.
Answers get 30 seconds of grace for the round trip. `startedAt` is set by the server on a portal
start; an app attempt falls back to its own `startedAt`, then to `@self.created`.

### D6: Auto hand-in on the first request after the deadline
There is no timer on the server. Any `available`, `start`, `answer` or `submit` request that finds
the pupil's attempt past the deadline plus grace hands it in first, then answers as for a handed-in
attempt. The portal also hands in when its countdown ends.

### D7: Released means graded and published
Learniq has no result-release flag. The teacher's act of release is publishing the grade: a
`graded` attempt is released when its GradeEntry is `published` or `revised` and not held back by a
future `visibleFrom`. A graded attempt without a GradeEntry (no curriculum component) is released on
grading, which is the teacher's final act for it.

### D8: Items leave learniq parsed, in the drawn order, without answers
`PortalItemPresenter` reads the item body only: the prompt from `prompt`/`qti-prompt` or the body's
own text, choices from `simpleChoice`/`qti-simple-choice` (and the inline and associable variants),
`sources`/`targets` from the two match sets. It never reads `responseDeclaration`, so no correct
answer can leave. Options follow the attempt's frozen `optionOrder`. XML is parsed with network
access off and errors suppressed.

### D9: The stored answer shape, and the scoring fix
Answers are stored as `{value: X}`, the shape `TakeAssessmentView` writes and `ItemAnalysisService`
and `AssessmentScoringView` read. `AssessmentScoringHandler` compared the whole object with the
correct response, so an app attempt never earned a point for a closed item. It now unwraps
`value` first; a bare value scores as before. The edit stays inside `scoreResponse()`, off the lines
PR #1047 changes.

### D10: Proctored tests stay in the app
A test with a `proctoring` configuration (native test mode or a provider) is not offered in the
portal, which has no fullscreen or navigation lock and no proctoring session.

### D11: learnerRef and the title are stamped by the attempt gate
The portal lists attempts by `AssessmentResult.learnerRef`. The attempt gate already runs one
server stamp for every create it lets through (`AssessmentResultAudience`), in a fixed order after
the veto. `AssessmentResultPortalStamp` runs next to it and stamps `learnerRef` (profile of
`learnerId`) and `assessmentTitle`, so app attempts appear in the portal too and a client cannot
point an attempt at another pupil's list. Both properties are `readOnly`.

## API Design
See contract.md for the five endpoints, payloads and error codes.

## Database Changes
Schema-only: `AssessmentResult` gains `learnerRef` (nullable uuid, readOnly) and `assessmentTitle`
(nullable string, readOnly). AssessmentResult 0.1.0 to 0.2.0; register `info.version` bumped. See
migration.md.

## Nextcloud Integration
- Controllers: `PortalAssessmentController` (5 routes, `#[PublicPage]`, `#[NoCSRFRequired]`,
  `#[AnonRateLimit]`, `#[BruteForceProtection]`).
- Services: `PortalAssertionVerifier` (`OCP\IConfig`), `PortalLearnerResolver`
  (`OCP\IUserManager`), `PortalAssessmentCatalogue`, `PortalAttemptService`, `PortalResultReader`,
  `PortalAttemptStore` (OpenRegister `ObjectService`, `TransitionEngine`), `PortalAttemptClock`,
  `PortalItemPresenter`, `PortalMessages` (`OCP\L10N\IFactory`), existing `LessonReleaseEvaluator`
  and `AssessmentAccessPolicy`.
- Events/Hooks: existing listeners fire on the writes; the attempt gate gains
  `AssessmentResultPortalStamp`.

## Declarative-vs-imperative decision (ADR-031)

| Behaviour | Path | Why |
|---|---|---|
| Assertion check | imperative, receiver | an authentication edge, not data |
| Test rules on a portal attempt | imperative, endpoint services | the listeners exempt user-less callers; `maxAttempts` and the deadline have no declarative home |
| Attempt lifecycle | declarative, existing `x-openregister-lifecycle` (`submit`) | fired through `TransitionEngine` |
| learnerRef and title | imperative stamp in the existing gate | reads LearnerProfile and the test |
| Portal surface | declarative, contribution manifest | portaliq renders it |

## Security Considerations
- Authentication: assertion only (D1, D2); 401 plus throttling on failure; audience `student`.
- Identity: the learner comes only from portaliq's stamp; the attempt must be the pupil's (404).
- Least privilege: writes run as the pupil (D3); reads without RBAC are filtered by the pupil (D4).
- No answer leaves before release (D7, D8); the attempts collection exposes no responses.
- Time and attempts are enforced on the server (D5, D6).
- Rate limits are generous per route because every forward comes from portaliq's own address.
- Input: `response` is validated per type and capped at 20,000 characters.

## File Structure
```
lib/Portal/PortalAssertionVerifier.php                 new
lib/Controller/PortalAssessmentController.php          new
lib/Service/Portal/LearnerProfileLookup.php            new (identical to assignment-portal-wiring's)
lib/Service/Portal/PortalLearner.php                   new
lib/Service/Portal/PortalLearnerResolver.php           new
lib/Service/Portal/PortalOutcome.php                   new
lib/Service/Portal/PortalMessages.php                  new
lib/Service/Portal/PortalAttemptStore.php              new
lib/Service/Portal/PortalAttemptClock.php              new
lib/Service/Portal/PortalItemPresenter.php             new
lib/Service/Portal/PortalAssessmentCatalogue.php       new
lib/Service/Portal/PortalAttemptService.php            new
lib/Service/Portal/PortalResultReader.php              new
lib/Service/AssessmentResultPortalStamp.php            new
lib/Listener/AssessmentAttemptGateListener.php         runs the portal stamp
lib/Lifecycle/AssessmentScoringHandler.php             unwraps {value}
lib/Portal/PortalContributionProvider.php              studentTests + five actions
lib/Settings/learniq_register.json                     AssessmentResult 0.2.0
lib/Settings/learniq_mock_register.json                seeds carry learnerRef and a title
appinfo/routes.php                                     five routes
tests/Stubs/Service/ObjectService.php                  runAs() mirror
tests/Unit/...                                         a test class per new class
docs/user-guide/user/06-grading.md                     a section on tests in the portal
```

## Seed Data

### Schema: `assessment-result`
| Field | Object 1 | Object 2 | Object 3 |
|-------|----------|----------|----------|
| slug | assessmentresult-assessmentresult-1-1 | assessmentresult-assessmentresult-2-2 | assessmentresult-assessmentresult-3-3 |
| learnerRef | 00000000-0000-4000-8000-000000000000 | 00000000-0000-4000-8000-000000000001 | 00000000-0000-4000-8000-000000000002 |
| assessmentTitle | Toets hoofdstuk 3 | Tentamen statistiek | Leesvaardigheid Engels |
| lifecycle | in-progress | submitted | graded |

## Trade-offs
- Acting as the pupil needs a Nextcloud account per pupil. Every LearnerProfile already requires
  `ncUserId`; the alternative, a system principal, is what D3 rules out.
- Two writes on hand-in (`submittedAt`, then the transition), as the app does: the `submit`
  transition declares no inputs, so it cannot carry `submittedAt`.
- Two starts racing can both create an attempt; the portal sends one start per click and resumes
  afterwards.
