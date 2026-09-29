# Design: send a learner to a different next step depending on how they did, and preview a course as a learner

## Context

At development `acdf1dd5`:

- `lib/Service/LessonReleaseEvaluator.php` evaluates `lesson-completed` (`evaluateLessonCompletedCondition`) and `assessment-min-score` (`:357`, best graded attempt of the learner) and returns `{blocked, reason}`.
- `src/views/LessonComposer.vue:483` holds the composer with a single lesson preview; `src/views/LessonPlayer.vue` plays at `/courses/:courseId/lessons/:lessonId/play`.
- Completion is written as `LessonCompletion`; xAPI statements go through the `lrs` routes (`appinfo/routes.php:67`).
- `Lesson` already carries `releaseConditions`; `nextStepRules` follows the same shape so the composer can reuse its condition editor.

## Goals / Non-Goals

**Goals**
- A course can branch on results, and an author can experience the branching without side effects.

**Non-Goals**
- Adaptive content generation by AI.
- Per-question branching inside a lesson.

## Decisions

### D1: Rules reuse the release condition language

A second condition language would double the editor and the tests. `score-below` is the only addition, the negation of `assessment-min-score`.

### D2: Preview is a mode of the same player

A second player would drift from the real one. The mode flag turns writes off at the store and at the server, so a bug in one layer is caught by the other.
