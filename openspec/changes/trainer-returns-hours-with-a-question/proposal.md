---
kind: spec
depends_on: [internship-hours, site-workplace-trainer-portal-design, site-guardian-portal-design]
---

# Proposal: trainer-returns-hours-with-a-question

## Why

Proof run 3, esdoornveen and wilgenboom:
- **Petra's weeks to approve** read "2026-W40". There was no student name, no days and no description of the work.
- **There was no "Terugsturen met een vraag".** Approving zero hours with a note worked by API, but the student saw the note nowhere.
- **The guardian's absence form** asked for two dates and a required reason. The MobielDetail board asks one "Wanneer?" question, and the note is optional.
- **A student's pages read "u"** in four strings.

## What changes

The schema changes are additive or relaxing, with a minor version bump: register 0.41.0 goes to 0.42.0.

- **`bpv-hour-week`** (0.3.0) gains three fields:
  - `description`: what the student did, optional, asked on her week form as "Wat deed je?";
  - `learnerName` and `weekLabel` ("28 september tot en met 2 oktober"): readable copies kept by `ReadableCopies` and backfilled by `BackfillReadableCopies`.
- **The trainer:**
  - The weeks to approve show the student, the days, the hours and what was done. The overview's task is titled with the student's name.
  - **`returnHourWeek`** ("Terugsturen met een vraag") is an endpoint action to `POST /api/portal/hour-weeks/send-back`. It needs the week and her question, and approves no hours whatever the form says. Only a week of her own student that still waits can be sent back.
  - The hours page carries both forms. The overview's "Uren goedkeuren" opens that page, because a button that posts an action straight away sends no week and is refused.
- **The student** reads the trainer's note on her hours page ("Opmerking van je praktijkopleider").
- **`excuse-request`** (0.5.0) no longer requires `dateTo` and `reason`.
  - The guardian's form asks "Wanneer?" with today and tomorrow as cards, plus an optional note.
  - `ExcuseRequestOwnerStamp` (through `AbsenceReportDefaults`) stamps the first day as the last, and the words of the kind as the note, when a report leaves them out.
- **Pupil wording:** four pupil-only strings now use "je". A test fails if any string of the pupil's manifest reads "u" or "uw" in Dutch.
- **Seeds:** every mbo week names its student and days. Milan's and Aylin's waiting weeks carry a description.
