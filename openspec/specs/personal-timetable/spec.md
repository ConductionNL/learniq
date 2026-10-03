# personal-timetable Specification

## Purpose
TBD - created by archiving change personal-timetable. Update Purpose after archive.

## Requirements

### Requirement: A signed-in user can see their own upcoming sessions

The system MUST provide a personal timetable that returns the caller's own scheduled
`Session` objects for a time window. The caller's sessions MUST be resolved by first
determining the cohorts the caller belongs to — as a teacher via `Cohort.teacherIds`, and
as a learner via `Cohort.learnerIds` and/or `Enrolment.cohortId` — then returning the
`Session` objects whose `cohortId` is one of those cohorts and whose `startsAt`/`endsAt`
fall within the requested window, ordered by `startsAt`. Each returned Session MUST additionally project
`roomId` and, when set, the referenced `Room`'s `name`/`capacity`/`facilities`, plus `lifecycle`,
`substituteTeacherId`, `changeReasonKind`, and `changeReason` — so a caller can see, from their own
timetable alone, that a Session has been cancelled or has a substitute teacher, without a separate lookup.
The response MUST also include a same-day `changes` list: `Session` objects belonging to the caller's own
cohorts whose `cancel` or `substitute-teacher` transition occurred today (regardless of whether the
Session's own `startsAt` falls inside the requested `from`/`to` window) — the "dagrooster" surface for
today's disruptions to a caller's schedule. All reads MUST go through OpenRegister `ObjectService` so RBAC
and tenancy scope the result; the caller MUST NOT receive a session for a cohort they do not belong to, and
MUST NOT receive another cohort's `TimetableConflict` data (that queue remains admin/coordinator-only). A
caller with no cohorts MUST receive an empty timetable (not an error) and an empty `changes` list.

#### Scenario: A learner sees this week's sessions for their enrolled cohorts

- **GIVEN** a learner enrolled in one or more cohorts that have scheduled sessions this week
- **WHEN** the learner requests their timetable for the current week
- **THEN** the system MUST return the `Session` objects for those cohorts within the week, ordered by `startsAt`, each with `title`, `startsAt`, `endsAt`, `location`, `roomId` (and resolved Room detail when set), `lifecycle`, `substituteTeacherId`, `changeReasonKind`, and `changeReason`
- **AND** sessions of cohorts the learner is not in MUST NOT appear

#### Scenario: A teacher sees the sessions of the cohorts they teach

- **GIVEN** a teacher listed in `teacherIds` of one or more cohorts with scheduled sessions
- **WHEN** the teacher requests their timetable
- **THEN** the system MUST return the sessions of the cohorts they teach for the window, with the same
  Room/substitution detail projected

#### Scenario: A user with no cohorts gets an empty timetable

- **GIVEN** a signed-in user who is neither a teacher of nor enrolled in any cohort
- **WHEN** they request their timetable
- **THEN** the system MUST return an empty list (HTTP 200) and an empty `changes` list, never an error

#### Scenario: Today's cancellation surfaces in the dagrooster changes list even for a future Session

- **GIVEN** a learner enrolled in a cohort with a Session scheduled for tomorrow
- **WHEN** a teacher cancels that Session today
- **THEN** the learner's timetable response for today includes the cancelled Session in its `changes` list,
  even though the requested window is "today" and the Session's own `startsAt` is tomorrow

@e2e exclude the cross-object resolution + windowing is unit-tested against seeded cohorts/sessions; a Playwright week-view smoke is a follow-up once seed data lands.

### Requirement: The timetable is a read surface only, over existing objects

The personal timetable MUST NOT introduce a new schema, new storage, or a scheduling
engine. It MUST consume the existing `Session`, `Cohort`, and `Enrolment` objects through
`ObjectService`. Creating or editing sessions remains owned by the existing session /
attendance surfaces; the timetable only reads. Projecting `Room` detail, substitution fields, and the
same-day `changes` list added by this change MUST NOT change this invariant: every projected field is read
from the existing `Session`, `Cohort`, `Room`, and `Enrolment` objects (the `Room`/substitution fields being
additive `Session` fields introduced by the `school-structure` delta of this same change, and `Room` being a
pre-existing read-only lookup) through `ObjectService`; `TimetableController` gains no new write endpoint.

#### Scenario: No new persisted state is created by viewing a timetable

- **WHEN** any user opens their timetable
- **THEN** the system MUST only read existing `Session`/`Cohort`/`Enrolment` objects
- **AND** MUST NOT create or mutate any object

@e2e exclude read-only invariant asserted by the controller unit test (no write calls).

#### Scenario: Viewing the extended timetable, including the same-day changes list, still creates no persisted state

- **WHEN** any user opens their timetable, including the same-day `changes` list and Room/substitution
  projection
- **THEN** the system only reads existing `Session`/`Cohort`/`Room`/`Enrolment` objects
- **AND** MUST NOT create or mutate any object, and no new controller write endpoint is added

@e2e exclude read-only invariant asserted by the controller unit test (no write calls); mirrors the existing scenario for the base read surface.

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

### Requirement: An administrator sets up a display screen

A user in `team-leads` or `compliance-officers` MUST be able to create a display screen for a location, limited to chosen rooms or groups, choose whether it shows today, today and tomorrow, or changes only, and get its secret address once. They MUST be able to renew or revoke the address; a revoked address MUST stop working at once. The stored secret MUST be a hash that no route returns.

#### Scenario: A team lead puts the hall screen live

- **GIVEN** a team lead on the display screens page
- **WHEN** they create "Aula gebouw A" for the main location showing today, and choose "Create address"
- **THEN** the address is shown once, with a note that it will not be shown again

### Requirement: A display screen shows today's lessons and changes without a signed-in user

The screen's address MUST show, without a signed-in user, the day's lessons in its scope with time, group, subject, room and, when set, the teacher code, with cancelled lessons and lessons with another teacher or room marked. The page MUST refresh itself every minute and MUST keep showing the last data with the time it was updated when a refresh fails.

#### Scenario: The hall screen shows a cancelled lesson

- **GIVEN** the screen "Aula gebouw A" and the lesson "Wiskunde B, 4 havo" at 10:15 cancelled this morning
- **WHEN** the screen's browser opens its address
- **THEN** the list shows the 10:15 lesson for 4 havo marked cancelled

#### Scenario: A revoked address shows nothing

<!-- @e2e exclude Public route answer; covered by DisplayScreenPublicControllerTest::testRevokedTokenIsNotFound. -->

- **GIVEN** a screen whose address was revoked
- **WHEN** anyone requests `GET /api/public/display/{token}` with the old token
- **THEN** the answer is 404

### Requirement: A display screen never shows personal data

The screen's output MUST NOT contain a learner's name or id, a user id, the free-text reason of a change, or the people affected by a change.

#### Scenario: A sick teacher's reason stays private

<!-- @e2e exclude Output shape of a public route; covered by DisplayScreenPublicControllerTest::testOutputKeysArePinned. -->

- **GIVEN** a cancelled lesson with the reason "Meneer De Vries is ziek"
- **WHEN** the screen loads its data
- **THEN** the lesson is marked cancelled
- **AND** the answer holds no reason text and no names of learners
