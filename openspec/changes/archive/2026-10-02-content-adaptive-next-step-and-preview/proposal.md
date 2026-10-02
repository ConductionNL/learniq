---
kind: code
---

# Send a learner to a different next step depending on how they did, and preview a course as a learner

## Why

GLR requirements 206628 (adaptivity: learners are led automatically to the next part when they complete a question or component correctly) and 206630 (teachers view the material and learning environment from the student's perspective) are both in the same tender, and both touch the lesson player. `LessonReleaseEvaluator` (`lib/Service/LessonReleaseEvaluator.php`) evaluates `lesson-completed` and `assessment-min-score` conditions that block or allow a lesson, so gating is built; branching is not. `src/views/LessonComposer.vue` has a per-lesson preview, but there is no way to move through the course as a learner does. Five competitors rate yes on each. Both rows are tender demand rows and share the lesson player, so they are one change.

The rows share one screen or service, so they are one change.

### Matrix rows (`learniq` `openspec/parity/capabilities.json`)

| row | capability | Today |
|---|---|---|
| `cont-adaptive-next-step` | Send a learner to a different next step depending on how they did. | `partial`: `partial`: release conditions can hold a lesson back until a score is met, but no rule sends a learner to a different lesson depending on the result |
| `cont-preview-as-learner` | See a course exactly as a learner sees it. | `partial`: `partial`: the composer has a preview of one lesson; a teacher cannot walk the whole course as a learner, with release rules applied and without leaving records |

### Demand

- `cont-adaptive-next-step`: tender, https://www.tenderned.nl/aankondigingen/overzicht/415112
- `cont-preview-as-learner`: tender, https://www.tenderned.nl/aankondigingen/overzicht/415112

### Competitors rated yes

- `cont-adaptive-next-step`, moodle: "source read at moodle/moodle v5.2.3: public/mod/lesson/pagetypes/multichoice.php:186 (jump depends on correct or wrong answer) + public/mod/lesson/locallib.php:2450 jumpto_is_correct; a lesson sends the learner to different pages "
- `cont-adaptive-next-step`, moodle-workplace: "read 2026-09-26: https://docs.moodle.org/502/en/Restrict_access_settings; 'You can specify a condition on any grade in the course ... either a minimum value ... a maximum value', e.g. 'one activity with a maximum of 7 and another "
- `cont-adaptive-next-step`, ilias: "source read at ILIAS-eLearning/ILIAS v11.4: components/ILIAS/Course/classes/Objectives/class.ilLOEditorGUI.php:391-394 (learning objectives with a placement test, 'Learning material is recommended on the basis of a participant's i"
- `cont-adaptive-next-step`, totara: "read 2026-09-26: https://totara.help/docs/restricting-access-based-on-grades; you can 'restrict access for an activity based on grades a user has achieved on other activities', e.g. learners scoring below a threshold see a remedia"
- `cont-adaptive-next-step`, chamilo: "source read at chamilo/chamilo-lms v3.0.1: public/main/exercise/exercise_submit_modal.php:221-244 (adaptive destinations: next question or failure destination per answer) + public/main/exercise/answer.class.php:42 new_destination "
- `cont-preview-as-learner`, moodle: "source read at moodle/moodle v5.2.3: public/course/switchrole.php:58 role_switch (Switch role to Student) + public/course/loginas.php:43 (log in as a specific learner); the teacher sees the course with student rights, and an admin"
- `cont-preview-as-learner`, moodle-workplace: "read 2026-09-26: https://docs.moodle.org/502/en/Switch_roles; switching role lets a teacher 'see what the course would look like to someone with that role'. Workplace includes core per https://support.moodle.com/support/solutions/"
- `cont-preview-as-learner`, ilias: "source read at ILIAS-eLearning/ILIAS v11.4: components/ILIAS/Container/MemberView/class.ilMemberViewGUI.php:58 ('Show Member View' action) + components/ILIAS/Container/MemberView/class.ilMemberViewSettings.php:133 (activate for a "
- `cont-preview-as-learner`, totara: "read 2026-09-26: https://totara.help/docs/enable-role-switching; role switching lets trainers and course managers 'view courses as they would appear to Learners'."
- `cont-preview-as-learner`, chamilo: "source read at chamilo/chamilo-lms v3.0.1: assets/vue/components/StudentViewButton.vue:5 (Switch to student view) + src/CoreBundle/Controller/PlatformConfigurationController.php:91 + src/CoreBundle/Controller/CourseController.php:"

## What Changes

- Add `nextStepRules` to `Lesson`: an ordered list of `{when, goToLessonId}` where `when` reuses the release condition shapes (`assessment-min-score`, `lesson-completed`) plus a `score-below` form, and a default next lesson.
- Add `NextStepResolver` beside `LessonReleaseEvaluator` and have the player ask it for the next lesson after a completion.
- Add Preview as learner to the course page: it opens the player in preview mode, applies release conditions and next step rules with a chosen simulated result, and writes no `LessonCompletion`, no xAPI statement and no result.

## Capabilities

### New Capabilities

- `content-adaptive-path`
- `content-preview-as-learner`

### Modified Capabilities

- None in delta form.

## Impact

- **Register**: `Lesson.nextStepRules` and `Lesson.defaultNextLessonId`.
- **Backend**: `NextStepResolver`, a route that resolves the next lesson, a preview flag that the completion and xAPI writers honour.
- **Frontend**: `LessonPlayer.vue`, a next step editor in `LessonComposer.vue` (in its own component file), a Preview as learner action.
- **Risk**: the preview flag must be honoured by every writer; the design lists them and a test asserts none writes.
