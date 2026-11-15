# Design: make a part of a learning path mandatory for one person and optional for another

## Context

At development `acdf1dd5`:

- `lib/Settings/learniq_register.json` `Programme` has `courseIds`, `requiredCompetencyIds`, `selfEnrolment`, no per-course flag.
- There is no programme progress service or widget (checked again at `3db526df`, 30 Sep).
- `Enrolment.mandatory` (boolean, default false, "compliance-mandatory assignment") and `Enrolment.programmeId` exist.
- The enrolment form is on `/enrolments`; the learner home widget lists enrolments.

## Goals / Non-Goals

**Goals**
- A path can require some parts and leave others open, and one person can be treated differently.

**Non-Goals**
- Ordering or prerequisites between parts (`content-adaptive-next-step-and-preview` and release conditions cover branching).

## Decisions

### D1: Default on the programme, truth on the enrolment

The enrolment keeps the per-person value the register already has, so reports that read `Enrolment.mandatory` keep working; the programme only supplies the default at enrolment time.

### D2: Existing programmes are unchanged

A programme without `mandatoryCourseIds` (or with an empty list) behaves as before: every course enrolment starts with `mandatory` false and the flag on the enrolment decides.

### D3 (30 Sep, build): a course list, not a requirements map

The proposal named `Programme.courseRequirements`, an object keyed by course. The generic form cannot edit an object map, so the author could not set it. Built instead: `Programme.mandatoryCourseIds`, an array of Course uuids next to `courseIds`, which the generic form edits the same way it edits `courseIds`. A course on the list is a mandatory part; every other course of the programme is optional; a course on the list that is not in `courseIds` is ignored. One class, `ProgrammeRequirements::mandatoryFor(programme, courseId)`, gives the default.

### D4 (30 Sep, build): both enrolment paths take the default

At HEAD a person is enrolled in a programme in two places: the catalogue sign-up (`CatalogueSignUpService::signUpProgramme`, the learner or the portal) and admission placement (`ApplicationConversionHandler::createEnrolments`). Both now set `mandatory` from `ProgrammeRequirements`. Placement also writes `programmeId` on each enrolment, which it did not before, so the progress can find the parts. Staff who enrol someone course by course on /enrolments already set `mandatory` per enrolment in the generic form; that is the per-person switch (the manager scenario), and it needs no new control.

### D5 (30 Sep, build): progress is new code, read from the enrolments

There was no programme progress anywhere at HEAD. `ProgrammeProgress::forLearner(userId)` reads the learner's enrolments that name a programme and counts, per programme, the enrolments with `mandatory` true that are `completed`. Optional parts are listed apart. A programme where none of the learner's parts is mandatory (every programme from before D3) counts every part, so it does not read 100 percent from the start. A withdrawn part counts nowhere. The learner reads it through `GET /api/programmes/progress` (session user only) in the new "My programmes" widget on the learner home.

### D6: a mandatory part is also a mandatory training

`Enrolment.mandatory` already feeds the compliance roll-up and the "My mandatory training" widget. A mandatory programme part appears there too. That is intended: for ProRail a mandatory part of a learning path is mandatory training.
