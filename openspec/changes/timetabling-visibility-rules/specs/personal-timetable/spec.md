# personal-timetable Specification

## ADDED Requirements

### Requirement: A school sets whose timetables each role may see

A user in `team-leads` or `compliance-officers` MUST be able to set, for learners and for instructors, which group, teacher and room timetables they may open: own only, related (for a learner, the teachers and rooms of their own lessons), or all. Without a setting the defaults MUST apply: learners their own groups and related teachers and rooms, instructors all.

#### Scenario: A school lets learners see every room

- **GIVEN** a team lead on the learniq settings page
- **WHEN** they set learners to see all rooms and save
- **THEN** a learner's timetable picker lists every room of the school

### Requirement: A user opens another timetable the school allows

Learniq MUST offer a Timetables page where a user picks a group, teacher or room and sees that week's lessons, listing only the timetables the policy allows the user, and `GET /api/timetable/of` MUST refuse any other with a reason.

#### Scenario: A learner looks up their maths teacher

- **GIVEN** learner m.yilmaz whose lessons include "Wiskunde B" taught by j.devries, and the default policy
- **WHEN** m.yilmaz opens "Timetables", chooses teachers and picks j.devries
- **THEN** the week shows j.devries's lessons

#### Scenario: A learner cannot open another group

<!-- @e2e exclude Access rule on an endpoint; covered by TimetableVisibilityServiceTest::testLearnerOwnGroupsOnly. -->

- **GIVEN** the default policy and learner m.yilmaz in 4 havo A
- **WHEN** m.yilmaz requests `GET /api/timetable/of?kind=cohort` for 5 vwo B
- **THEN** the answer is 403 saying the school does not allow it

### Requirement: The API follows the same line

`Session` MUST carry an authorization block under which only staff groups read sessions through the object API, so that a learner cannot read lessons outside the policy by calling the object API. Every learner-facing screen MUST read lessons through learniq's timetable endpoints.

#### Scenario: A learner cannot list all lessons

<!-- @e2e exclude Register authorization on the object API; covered by TimetableVisibilityRegisterTest. -->

- **GIVEN** a learner in no staff group
- **WHEN** they list `session` objects through OpenRegister's object API
- **THEN** no lesson comes back
