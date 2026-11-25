## ADDED Requirements

### Requirement: Noor has a timetable on every school day of the story week

The vo example set MUST give H4b lessons on every school day of the story week, Monday to Friday, so a pupil's "Je rooster vandaag" is never empty on a school day after the load moves the week.

#### Scenario: Noor on a Thursday
- **GIVEN** the vo set is loaded this week
- **WHEN** Noor opens her overview on Thursday
- **THEN** "Je rooster vandaag" lists that day's lessons
- @e2e exclude covered by PHPUnit `StudentTimetableTest::testNoorHasLessonsOnEverySchoolDayOfTheStoryWeek`
