---
kind: code
depends_on: []
---

# Proposal: timetabling-lesson-note

## Summary

A teacher adds a short note to one lesson, or to every lesson of a series: the topic, what to bring, homework, or instructions for whoever covers the lesson. Learners see the note on the lesson in their timetable and on the lesson page; a note marked for the covering teacher is shown only to staff and to the substitute. A substitute teacher also gets the lessons they cover in their own timetable, which they do not today.

## Why

Row `tt-lesson-note` of planninq's matrix (`ConductionNL/planninq openspec/parity/capabilities.json`, planninq#665), owed to learniq ("Add a note or topic to a lesson that students and a covering teacher see in the timetable."), `none`. Decision: build, all four timetabling competitors rate yes.

- zermelo, yes: https://support.zermelo.nl/guides/medewerker/een-opmerking-bij-uw-les-zetten "U kunt uw les een onderwerp geven. Dit onderwerp zien de leerlingen als ze de les in hun rooster bekijken".
- untis, yes: https://help.untis.at/hc/de/articles/360012885700 "Info zur Stunde", "Benutzer können auch ohne diesem Recht in ihrem Stundenplan die von Lehrern eingetragenen Informationen zur Stunde lesen"; https://www.untis.at/produkte/webuntis/online-vertretungsplanung, absent teachers leave "eine Notiz für die Vertretungslehrkraft".
- xedule, yes: https://support.xedule.nl/hc/nl/articles/37415902430738-Beheer-Configuratie-MyX, "Publicatietekst ... een tekst die op het individuele lesmoment is ingevoerd", shown in My Xedule.
- timeedit, yes: https://timeedit.com/platform/scheduling/viewer "Comments and feedback on reservations".

Under D10 the timetable data moves to planninq, and planninq's school-timetable-target leaves rendering, substitution and notices with learniq; a note on a lesson is part of that operational layer.

## What learniq has today

Read at learniq `development` 8bb8401d.

- `Session` (`lib/Settings/learniq_register.json:6130`): `title`, `materialIds`, `assignmentIds`, `changeReason` (the reason for a cancellation or substitution); no note for learners or a substitute.
- `TimetableProjector::projectSession()` (`lib/Service/TimetableProjector.php:201`) shapes each lesson for `GET /api/timetable/mine` (`lib/Controller/TimetableController.php:111`) and `src/views/MyTimetable.vue` renders time, title and room per lesson (:127-165).
- `TimetableController::resolveCallerCohortIds()` (:164-230) finds the caller's lessons through cohorts where the caller is a teacher or learner, and through enrolments. A teacher set as `substituteTeacherId` on a lesson of another cohort does not get that lesson.

## What this change builds

1. A `LessonNote` schema: the lesson it belongs to, the text, an optional topic, the audience (`learners` or `cover`), the author.
2. On the lesson page and from the timetable's lesson menu, "Add note" for the lesson's teachers and coordinators, with "Apply to every lesson of this course for this group in the next weeks" as an option.
3. The timetable endpoint returns each lesson's notes the caller may see, and `MyTimetable.vue` shows a note marker and the text in the lesson's detail.
4. The timetable endpoint also returns lessons where the caller is the substitute teacher, marked as cover.

## Out of scope

- Attachments on a note (materials already do that).
- Notes on a whole day or on the school (announcements are portaliq's, D1).

## Affected projects

- [x] `learniq`: register (new `LessonNote`), `TimetableController`, `TimetableProjector`, `MyTimetable.vue`, the SessionDetail page, seed data, l10n.

## Risks

- A cover note can hold personal remarks about learners. Mitigation: a `cover` note is never returned to a learner, by the endpoint or by the object API.
