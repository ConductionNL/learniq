---
kind: code
depends_on: []
---

# Proposal: enrolment-catalogue-self-signup

## Summary

Learners get a course catalogue: every published course and programme that is open for sign-up, with search and filters on level, language, subject and provider. A learner signs up for a course, or for a whole programme (one enrolment per course of the programme), and withdraws again before starting. A course can be open (the learner is in at once) or on request (a teacher, HR officer or the learner's manager approves). Courses an outside provider's catalogue brings in through integriq show the provider's name, so learniq's half of the course marketplace is this same catalogue.

## Why

This change covers two rows of learniq's matrix (`openspec/parity/capabilities.json`).

`enr-sign-up-from-catalogue` ("Let a learner browse a catalogue and sign up for a course or a whole track."), rated `no`, `built.state: none`, built evidence `lib/Settings/learniq_register.json:2427`. Decision: build, five competitors rate yes.

- Demand row (changelog, counted as competitor evidence): https://totara.com/articles/whats-new-totara-version-20/, Totara 20: "Learners can now enrol to (and withdraw from) programs and certifications" by browsing the catalogue themselves.
- moodle-workplace, yes: https://docs.moodle.org/500/en/Moodle_Workplace_release_notes, Workplace 5.0: "Learners can now self-enrol in programs through a cleaner, more intuitive interface in the Learning Catalogue".
- ilias, yes: "components/ILIAS/Course/classes/class.ilCourseRegistrationGUI.php:225 (direct registration) + components/ILIAS/LearningSequence/classes/class.ilObjLearningSequenceAccess.php:38-55 (learner view and unparticipate)".
- totara, yes: https://totara.help/docs/self-enrolment-for-learners, "Learner selects the Enrol button" on a program and can later "select the Withdraw button".
- chamilo, yes: "assets/vue/router/catalogue.js:2 (/catalogue) + assets/vue/views/course/CatalogueCourses.vue:72 (subscribe to a course) + assets/vue/views/course/CatalogueSessions.vue:58 ... subscribeToSession".
- ispring-learn, yes: https://ispringhelpdocs.com/ispring-learn/course-catalog-in-the-user-portal-17301982.html, learners open the Course Catalog and "click on Add to My Courses"; tracks too (https://ispringhelpdocs.com/ispring-learn/adding-a-learning-track-to-catalog-100870315.html).
- moodle, partial: course categories with self enrolment, no track as a whole in core.

`cont-outside-provider-catalogue` ("Offer an outside training provider's catalogue inside the platform"), owner integriq, specified by integriq's change `connectors-course-marketplace` (integriq#2207). Tender: https://www.tenderned.nl/aankondigingen/overzicht/411287. That change writes provider courses into learniq as draft `Course` rows with `author` set to the provider and one LTI lesson, and names learniq's half: "showing the imported courses in its course catalogue with the provider named, letting an administrator publish the ones it offers, and enrolling a learner." This change is that half.

## What learniq has today

Read at learniq `development` a84b6273.

- `Enrolment` (`lib/Settings/learniq_register.json:2624`): `source` already has the value `self`, but `create` is limited to `instructors`, `hr`, `compliance-officers`, `team-leads`. Nothing in `lib/` or `src/` writes `source: self`.
- `Course` (`:1046`): read by every signed-in user, lifecycle `draft`, `published`, `archived` (publish guarded by `CoursePublishGuard`); `level`, `language`, `tags`, `subject`, `educationalLevels`, `author`, `prerequisiteCourseIds`. No sign-up setting.
- `Programme`: `courseIds`, the ordered courses of a track; published like a course.
- `EnrolmentPrerequisiteListener` refuses an enrolment whose prerequisites are not completed, on create (`openspec/specs/enrolment/spec.md:41-55`), for any caller.
- The learner home (`src/views/LearniqLearnerHome.vue`, `/learner`) shows only "My mandatory training". `/courses` is a staff index.
- `Enrolment`'s `activated` notification says "You have been enrolled in a mandatory course", which is wrong for a course a learner chose.

## What this change builds

1. `Course.selfEnrolment` and `Programme.selfEnrolment`: `closed` (default), `open`, `on-request`.
2. A learner catalogue page `/catalogue`: published courses and programmes with `selfEnrolment` not `closed`, as cards with name, provider (`author`), level, language, credits and a short description, with search and filters, and a detail view with Sign up, Request a place or Withdraw.
3. `POST /api/catalogue/courses/{id}/sign-up`, `POST /api/catalogue/programmes/{id}/sign-up` and `POST /api/enrolments/{id}/withdraw`: create one `Enrolment` per course with `source: self` (active at once for `open`, pending for `on-request`), refuse a closed or unpublished course, let the prerequisite listener speak, and let a learner withdraw only their own enrolment before it is completed.
4. Approval of requests: a "Sign-up requests" view for `instructors`, `hr`, `team-leads` and for the learner's manager (`managerId`), with Approve (the existing `activate`) and Decline (`withdraw` with a reason).
5. A notification text for a chosen course, separate from the mandatory course text.
6. For marketplace courses: a "Provider" filter from `author`, and on the staff course list a filter "Imported, not yet published" so an administrator reviews and publishes the ones the school offers.

## Out of scope

- Payment for a course (shillinq, see D12 and D19).
- Seat limits per course run and waiting lists (training sessions with capacity are a later change).
- The provider launch itself (integriq's `connectors-lti-platform-launch` and learniq's `content-lti-launch-through-integriq`).

## Affected projects

- [x] `learniq`: register (Course, Programme, Enrolment notification), a catalogue controller and service, a learner catalogue page and menu entry, a requests view, seed data, l10n.

## Risks

- A learner enrols another learner. Mitigation: the endpoint only ever writes the caller as `learnerId`; the register's staff-only create stays.
- A catalogue of thousands of provider courses. Mitigation: only published courses show; imported ones arrive as drafts and an administrator publishes a selection.
