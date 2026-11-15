# personal-timetable Specification

## ADDED Requirements

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
