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
