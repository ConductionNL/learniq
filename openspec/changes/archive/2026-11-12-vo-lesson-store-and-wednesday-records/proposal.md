---
kind: spec
depends_on: [vo-board-records-for-the-pupil-boards]
---

# Proposal: vo-lesson-store-and-wednesday-records

## Why

Four learniq boards on identity.conduction.nl/screens draw records the secondary school set does not hold, so the live page has nothing to show where the board draws a card. LqLlLesVolgen draws a Biologie lesson with a reading text, a video, a quiz and an assignment, and the set has no lesson at all. LqLlVandaag and LqLlRooster are drawn on Wednesday 7 October, with Frans cancelled in the 6th hour and the mentor hour in another room in the 7th, and the set's Wednesday has neither. LqStore draws three courses other schools share, and the set shares none.

## What changes

For the data: the generator `scripts/example-sets/vo.py`, the file it writes and the portal declaration. A new step, `add_lesson_and_store_boards()`, runs after every other step and draws no random number, so every existing uuid stays.

- **Biologie leerjaar 3 havo/vwo**: a published course by Anouk Smit with ten lessons and its own group, in which every pupil of 3HV-B is enrolled. Lesson 3, "cellen onder de microscoop", has four blocks: the reading text, the video "Zo stel je een microscoop scherp", the quiz "Oefenquiz cellen" and the assignment "Practicumverslag cellen", due Friday 16 October at 17:00. Its next lesson is "Les 4: Celdeling".
- **Fenna's Wednesday** follows LqLlVandaag: Nederlands, wiskunde, Biologie (lesson 3), Engels and geschiedenis in the rooms the board names, Frans cancelled in the 6th hour and the mentor hour in lokaal 0.10 instead of 1.12 in the 7th. The Wednesday's five lessons keep their uuids; the two changed hours are appended.
- **Teachers**: the Biologie teacher of the bovenbouw is called Anouk Smit, as the boards draw her, and gets an account in the portal declaration. 3HV-B gets its Biologie and Frans teachers.
- **The course store**: three `shared-course-package` rows, Betoog schrijven havo 4, Betogen en beschouwen and Argumenteren in de onderbouw, each with a small course package an install can import. They show on the store page once learniq's `registry_url` is `local`, which reads this instance's own register.

- **The HR Today subtitle** (LqHrVandaag) ends with "of Zuiddrecht College": a manifest line with `{school}`, filled from the instance's one `school` row. With several schools or a failed read, the line without a name stays.

`lesson`, `material` and `shared-course-package` are appended to the schema order. Every row gets the instance's organisation from the loader, as the other rows do.

## Not in this change

- The class and mentor line in the learner and guardian Today subtitles: the data exists (the pupil's `groupLabel` and the cohort's primary teacher), the dashboard's lines are static text per role.
- Setting `registry_url`: app configuration, a window step.
- The "Installed" state of a store card.
