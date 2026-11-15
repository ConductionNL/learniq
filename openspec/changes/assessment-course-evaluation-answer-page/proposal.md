---
kind: code
---

# A learner answers a course evaluation

## Why

GLR requirement 206641 (TenderNed) asks to create, change and use surveys and evaluations in a standardised way. Staff can create an `EvaluationCampaign` with questions and the eligibility guard for `CourseEvaluationResponse` `submit` is built (`lib/Lifecycle/CourseEvaluationEligibilityGuard.php`), but the matrix records "no learner answer page": an invited learner has nowhere to answer. Six competitors rate yes. The row is a tender demand row, so it is built.

One row, one change.

### Matrix rows (`learniq` `openspec/parity/capabilities.json`)

| row | capability | Today |
|---|---|---|
| `ass-run-a-survey` | Run a survey or course evaluation. | `partial`: `partial`: staff create campaigns and invitations and the submit guard exists, but no learner page shows an invitation or takes the answers |

### Demand

- `ass-run-a-survey`: tender, https://www.tenderned.nl/aankondigingen/overzicht/415112

### Competitors rated yes

- `ass-run-a-survey`, moodle: "source read at moodle/moodle v5.2.3: public/mod/feedback/lib.php:83 feedback_add_instance + public/mod/feedback/edit.php + public/mod/feedback/manage_templates.php (reusable question templates) + public/mod/feedback/analysis.php; "
- `ass-run-a-survey`, moodle-workplace: "read 2026-09-26: https://docs.moodle.org/502/en/Feedback_activity; teachers 'create and conduct surveys to collect feedback', 'ideal for course or teacher evaluations'. Workplace includes core per https://support.moodle.com/suppor"
- `ass-run-a-survey`, ilias: "source read at ILIAS-eLearning/ILIAS v11.4: components/ILIAS/Survey/classes/class.ilObjSurveyGUI.php + components/ILIAS/Survey/Execution/class.ilSurveyExecutionGUI.php:30 + components/ILIAS/Survey/Evaluation/class.ilSurveyEvaluati"
- `ass-run-a-survey`, totara: "read 2026-09-26: https://totara.help/docs/feedback-activity-question-types; the feedback activity offers question types including 'Longer text answer', 'Multiple choice (rated)' and 'Numeric answer'; the survey activity presents '"
- `ass-run-a-survey`, chamilo: "source read at chamilo/chamilo-lms v3.0.1: public/main/survey/create_new_survey.php:237 (anonymous option) + public/main/survey/survey.lib.php:190 store_survey + public/main/survey/reporting.php; the survey tool builds course eval"
- `ass-run-a-survey`, ispring-learn: "read 2026-09-26: https://ispringhelpdocs.com/ispring-learn/how-to-create-surveys-in-ispring-lms-128352655.html; create non-graded online quizzes 'to collect their views and feedback', e.g. Likert scale, as standalone items or insi"

## What Changes

- Add a My evaluations page for learners that lists open `EvaluationInvitation` objects with a due date.
- Add an answer form that renders the campaign `questions`, saves a `CourseEvaluationResponse` through the `submit` transition, and marks the invitation as responded without linking the answers to the learner.
- Show staff the response count and the mean score per campaign, never the individual responses when the anonymity policy is anonymous.

## Capabilities

### New Capabilities

- `course-evaluation-answering`

### Modified Capabilities

- None in delta form.

## Impact

- **Frontend**: a My evaluations page and an answer form in `src/manifest.d/`, results widget for staff.
- **Backend**: possibly a small endpoint that lists the caller's invitations; the submit transition and guard exist.
- **Database**: none.
- **Privacy**: the anonymity rule is asserted by a test against the real schema shape (`CourseEvaluationResponse` has no learner field).
