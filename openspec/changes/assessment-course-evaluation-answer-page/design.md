# Design: a learner answers a course evaluation

## Context

At development `acdf1dd5`:

- `lib/Settings/learniq_register.json` `EvaluationCampaign` (`questions`, `anonymityPolicy`, `closesAt`), `EvaluationInvitation` (`campaignId`, `learnerId`, `hasResponded`, `respondedAt`), `CourseEvaluationResponse` (`campaignId`, `courseId`, `answers`, `overallScore`, no learner id).
- `lib/Lifecycle/CourseEvaluationEligibilityGuard.php:110` resolves the caller from the session and passes only an eligible, not yet responded caller; it is bound to `submit`.
- The staff campaign page is at `/course-evaluation/campaigns`.

## Goals / Non-Goals

**Goals**
- The evaluation loop closes: invite, answer, read the result.

**Non-Goals**
- Free-form survey design beyond the campaign `questions` shape.
- Reminders (declared by `reminderSchedule`, separate).

## Decisions

### D1: Answers through the guarded transition

The page never writes the response directly; it uses the `submit` transition so the eligibility and anonymity guards stay the only path.

### D2: Small n suppression on the mean

A mean of three answers identifies people in a small class, so staff see the count and no mean under five.

### D3: The learner goes through learniq's endpoints, not the object API

`GET /api/evaluations/mine`, `POST /api/evaluations/{invitationId}/answer` and `GET /api/evaluations/campaigns/{campaignId}/results` (`CourseEvaluationAnswerController` over `CourseEvaluationAnswerService`). The learner id always comes from the session. An invitation that is not the caller's answers 404, so its existence is not confirmed; an answered invitation or a closed campaign (not `open`, or `closesAt` passed) answers 409; answers that miss a required question answer 422 and nothing is written. An `external-form` campaign shows its link and takes no answers here.

### D4: The response is stored without an owner, then submitted through the guard

The service writes the `CourseEvaluationResponse` draft with OpenRegister's `_unowned` save option, so the object's owner is the system and not the learner (a plain save stamps the session user as `@self.owner`, which would link every anonymous answer to a person). It then calls the `submit` transition, so `CourseEvaluationEligibilityGuard` still decides (D1) and `CourseEvaluationResponseSubmittedHandler` still flips the invitation. When the guard refuses, the draft is deleted again. Known limit: OpenRegister's audit trail records the acting user for the create and the transition; staff who can read audit trails of this schema could still pair a response with a person. Closing that needs an OpenRegister option to write an anonymous audit entry (drafted for Ruben, not in this change).

### D5: Which answer is the overall score

`overallScore` is the answer to the campaign's last rating question: the shipped campaigns end their rating block with "My overall rating of the training" (`q5`), and the seeded responses carry exactly that value. A campaign without rating questions stores no overall score.
