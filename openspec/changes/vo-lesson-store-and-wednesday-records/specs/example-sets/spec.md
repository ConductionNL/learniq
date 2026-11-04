## ADDED Requirements

### Requirement: The secondary school set holds the lesson, the Wednesday and the shared courses the pupil boards draw

The vo example set MUST hold a published course Biologie leerjaar 3 havo/vwo with ten lessons, its own group and an active enrolment in that group for every pupil of 3HV-B, whose third lesson carries a reading block, a video block, a quiz block and an assignment block that point at rows of the set. On Wednesday 7 October 2026, 3HV-B MUST have a cancelled Frans lesson in the 6th hour and a mentor hour in another room in the 7th, and its Biologie lesson that day MUST point at the third lesson. The set MUST hold three shared course packages, each with a course and lessons to install. The records MUST be added after every other object without a random draw, so no existing uuid moves.

#### Scenario: A fresh load carries the lesson, the Wednesday and the store
- **GIVEN** a fresh instance
- **WHEN** the operator loads the vo set
- **THEN** Fenna de Jong opens lesson 3 of Biologie leerjaar 3 havo/vwo and sees its reading text, video, quiz and assignment
- **AND** her timetable for the week of the load shows Frans cancelled and the mentor hour moved on the Wednesday
- **AND** with `registry_url` set to `local` the store page lists three shared courses
- @e2e exclude covered by PHPUnit `SecondarySchoolLessonAndStoreRecordsTest` and the generator check (`scripts/example-sets/vo.py --check`)
