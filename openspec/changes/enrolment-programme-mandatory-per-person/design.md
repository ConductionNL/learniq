# Design: make a part of a learning path mandatory for one person and optional for another

## Context

At development `acdf1dd5`:

- `lib/Settings/learniq_register.json` `Programme` has `courseIds`, `requiredCompetencyIds`, `selfEnrolment`, no per-course flag.
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

A programme without `courseRequirements` behaves as before: the flag on the enrolment decides.
