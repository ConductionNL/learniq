---
kind: spec
depends_on: [example-sets-are-the-four-schools, demo-dates-follow-the-load-week]
---

# Proposal: vo-timetable-every-school-day

## Why

Proof run 3: Noor's "Je rooster vandaag" was empty on every day except Monday 5 and Tuesday 6 October. The vo set gave H4b lessons only on those two days, so after the load moved the week, Wednesday to Friday had no timetable.

## What changes

- The vo generator adds the rest of H4b's week of 5 October, in the shape of the Monday: five or six lessons a day on Tuesday to Friday, with the story's teachers and rooms. Tuesday keeps its first hour (economie) and eighth hour (steunles wiskunde).
- The new lessons are written last, so no existing uuid moves. Their dates move with the load week like every other date.

## Not in this change

- Weeks other than the story week. The load moves the set to the week it runs in, so the story week is always the current week.
