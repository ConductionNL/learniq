# personal-timetable Specification

## ADDED Requirements

### Requirement: A teacher adds a note to a lesson

A teacher of a lesson's cohort, the lesson's substitute, or a user in `team-leads` or `compliance-officers` MUST be able to add a note with an optional topic to one lesson, or to every lesson of the same cohort and course in chosen weeks, for learners or for the covering teacher. An instructor who does not teach or cover the lesson MUST NOT be able to write a note on it.

#### Scenario: A teacher sets a topic for next week's lessons

- **GIVEN** the teacher of "Wiskunde B, 4 havo" on the lesson page of Tuesday's lesson
- **WHEN** the teacher chooses "Add note", enters "Hoofdstuk 4: kansrekening, neem je rekenmachine mee", ticks the next three weeks and saves
- **THEN** each of the four Tuesday lessons shows the note

#### Scenario: A teacher cannot write on another teacher's lesson

<!-- @e2e exclude Creating-event rule; covered by LessonNoteAuthorGuardTest::testOtherCohortTeacherIsRefused. -->

- **GIVEN** an instructor who does not teach or cover the lesson
- **WHEN** they create a `lesson-note` for it through the object API
- **THEN** the write is refused

### Requirement: Learners see a lesson's note in their timetable

The personal timetable MUST show learners the notes for learners on their own lessons, with the topic on the lesson and the text in its detail. A note for the covering teacher MUST NOT reach a learner through any route.

#### Scenario: A learner reads the topic before class

- **GIVEN** learner m.yilmaz in "Wiskunde B, 4 havo" and a learner note on Tuesday's lesson
- **WHEN** m.yilmaz opens "My timetable"
- **THEN** Tuesday's lesson shows a note marker and, opened, the topic and text

### Requirement: A substitute teacher sees the lessons they cover

The personal timetable MUST include the lessons where the caller is the substitute teacher, marked as cover, with all notes of those lessons, including the notes for the covering teacher.

#### Scenario: A substitute reads the cover note

- **GIVEN** teacher e.devries assigned as substitute for Tuesday's "Wiskunde B, 4 havo", which has a cover note
- **WHEN** e.devries opens "My timetable"
- **THEN** Tuesday shows that lesson marked "Cover"
- **AND** its detail shows the cover note
