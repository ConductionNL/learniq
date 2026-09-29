---
kind: code
depends_on: []
---

# Proposal: enrolment-self-join-work-group

## Summary

A teacher sets up work groups inside a course group (a class, a werkgroep, a training group), each with a name and a maximum size, and opens them for self sign-up until a date. Learners of that group see the work groups with their free places, join one, and can move or leave while sign-up is open. A full group takes nobody more. When a group assignment is handed in, the learner's work group fills the hand-in's members, so the teacher does not have to rebuild the groups.

## Why

Matrix: learniq `openspec/parity/capabilities.json`, row `enr-self-join-group-with-cap` ("Let learners join a work group themselves, up to a set size."), rated `no`, `built.state: none`, built evidence `lib/Settings/learniq_register.json:6339`. Decision: build, a tender asks for it and two competitors rate yes.

- Demand (tender): https://www.tenderned.nl/aankondigingen/overzicht/415112, matrix note "GLR requirement 206634: students enrol themselves in work groups within a course, with a maximum group size."
- ilias, yes: "source read at ILIAS-eLearning/ILIAS v11.4: components/ILIAS/Group/classes/class.ilObjGroupGUI.php:1511 (registration type direct join) and :1571 (limit members, registration_max_members) and :612 + components/ILIAS/Group/classes/class.ilGroupRegistrationGUI.php:136-189 (join form checks the member limit, offers the waiting list)".
- chamilo, yes: "source read at chamilo/chamilo-lms v3.0.1: public/main/group/settings.php:176-178 (learners may self-register in groups) + src/CourseBundle/Entity/CGroup.php:82 maxStudent + public/main/inc/lib/groupmanager.lib.php:1806 is_self_registration_allowed and :1907 (only while members are below the maximum)".
- moodle, partial: "public/group/group_form.php:68 (group enrolment key places a self-enrolling learner in that group) + public/mod/choice/mod_form.php:67 (choice with limited answers per option); self-enrolment into a group works without a size limit".
- moodle-workplace, totara and ispring-learn, partial: capacity on appointments, seminar events or sessions, not on course work groups.

## What learniq has today

Read at learniq `development` a84b6273.

- `Cohort` (`lib/Settings/learniq_register.json:5526`): a teaching, care or plusklas group with `learnerIds` and `teacherIds`; its authorization lets staff write and members read through `ncGroupId`. It is the course group, not a work group inside it.
- `GroupPlanSubgroup` (`:14034`): instructional level subgroups inside a PO group plan, set by the teacher; no size, no self sign-up.
- `Assignment.groupSubmission` ("When true, a Submission may list multiple learnerIds (group hand-in)"), but the hand-in screen writes only the current learner: `src/views/SubmitWorkView.vue:122-131` posts `learnerIds: [learnerId]`. A group hand-in therefore names one learner unless a teacher edits it.
- Nothing lets a learner choose a group, and nothing caps a group's size.

## What this change builds

1. A `WorkGroup` schema: cohort, optional course, name, `maxMembers`, `memberIds`, `selfJoinUntil`, lifecycle `open` or `closed`.
2. A "Work groups" tab on the cohort page for teachers: create groups (one by one or "make 8 groups of 4"), see members, move a learner, close sign-up.
3. A learner view listing the work groups of the learner's cohorts with free places and a Join, Move or Leave button.
4. `POST /api/work-groups/{id}/join` and `/leave`: check membership of the cohort, the date, the size and that the learner is in no other work group of the same set, then write.
5. The hand-in screen of an assignment with `groupSubmission` fills `learnerIds` with the learner's work group members.

## Out of scope

- A waiting list per work group (ilias has one; a later change if asked).
- Automatic group making by the teacher from criteria.
- Work groups across cohorts.

## Affected projects

- [x] `learniq`: register (new `WorkGroup`, `Assignment.workGroupSetName`), a controller and service, cohort detail manifest, a learner custom page, `SubmitWorkView.vue`, seed data, l10n.

## Risks

- Two learners take the last place at the same moment. Mitigation: the join endpoint re-reads the group under a lock key per group and refuses when full.
- A learner joins a group and never takes part. Mitigation: the teacher can move or remove members at any time; closing sign-up freezes self changes.
