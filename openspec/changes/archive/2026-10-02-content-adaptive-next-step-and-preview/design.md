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

### D3: The rule shape, as built

A rule is `{when, goToLessonId}`. `when` carries the release condition fields (`kind`, `lessonId`, `assessmentId`, `minScore`) plus `belowScore` for `score-below`. A score is the learner's best graded attempt, summed the way the release condition sums it (`LessonReleaseEvaluator::bestScore`, now public so both read one number); a learner with no graded attempt is neither above nor below a threshold, so the default applies. `lesson-completed` reads the learner's own completion statement (`LessonReleaseEvaluator::hasCompleted`). A half-written rule (no target, no condition, an unknown kind, no assessment or threshold) is skipped, never matched.

### D4: Same course is a pre-write veto

`LessonNextStepGuard` (on lesson create and update) reads every rule target and the default and refuses the write, with the reason, when one is not a lesson of the lesson's own course or cannot be read. It reads other rows, so it cannot be a schema rule.

### D5: Who may preview

A preview is for the Learniq staff views `admin` and `teacher` (`DashboardRoleService::resolveViews`), the same views that may read a lesson's release status without an enrolment. `GET /api/lessons/{id}/next-step?preview=1&score=` and `GET /api/courses/{id}/preview` answer 403 to anyone else. Outside a preview, `next-step` answers only a caller enrolled in the lesson's course, or staff, and always for the caller's own results: the route takes no learner.

### D6: The two layers that keep a preview from writing

Store layer: the player in preview mode (route query `preview=1&score=`) makes no write. Marking a lesson complete only changes the screen, a SCORM completion posts no statement, a quiz does not start, and an LTI tool or cmi5 package does not launch (they would record results elsewhere), shown as "This content does not run in a preview". Server layer: every write the player makes carries `X-Learniq-Preview: 1` when in a preview (`writeHeaders`), and `PreviewWriteGuard` refuses a create of `lesson-completion`, `assessment-result` or `xapi-statement` in such a request. A unit test counts the player's POSTs against its `writeHeaders(this.preview` calls, so a new writer without the header fails the suite. Release conditions are evaluated as for any staff caller (the author has no results of their own), so a preview does not stop at a locked lesson: it plays on and the banner names the reason a learner would see. Deciding release conditions from the simulated score as well is left out: the next step rules are what the tender rows ask to walk.

### D7: Where the author starts

The course page gets a "Preview as learner" header action. It opens `/courses/{id}/preview` (CoursePreviewView): the course's lessons in order, the simulated score, and a start button that opens the first lesson in the player in preview mode. The next step button in the player carries the preview on to the next lesson.
