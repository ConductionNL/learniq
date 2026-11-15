# enrolment Specification

## ADDED Requirements

### Requirement: A school offers optional lessons with a window and a capacity

A user in `instructors`, `team-leads` or `compliance-officers` MUST be able to create an optional lesson offer with its lessons, a capacity per lesson, the eligible cohorts and a sign-up window, either fixed dates or relative to each lesson (opening a number of days before it and closing a number of hours before it).

#### Scenario: A coordinator offers weekly extra maths

- **GIVEN** a coordinator on the optional lessons page
- **WHEN** they create "Keuzewerktijd wiskunde" for the havo 4 and 5 groups, four Thursday lessons, 24 places each, opening 7 days and closing 12 hours before each lesson, and open it
- **THEN** each lesson shows 24 free places and the date its sign-up opens

### Requirement: A learner signs up for an optional lesson inside the window

An eligible learner MUST be able to sign up for a lesson of an open offer while its window is open and a place is free, and MUST be able to withdraw inside the window. The sign-up MUST always be made in the caller's own name.

#### Scenario: A learner signs up for Thursday

- **GIVEN** learner j.bakker in havo 4 and the first Thursday lesson with 13 free places, its window open
- **WHEN** j.bakker opens "Optional lessons" and chooses "Sign up" on that lesson
- **THEN** the lesson shows j.bakker as signed up and 12 free places

#### Scenario: The window has closed

- **GIVEN** the first Thursday lesson starts in 10 hours and its window closes 12 hours before
- **WHEN** j.bakker opens "Optional lessons"
- **THEN** the lesson shows that sign-up has closed and offers no button

### Requirement: A coordinator places learners who missed the window

After the window closes, a coordinator MUST see per lesson the eligible learners who did not sign up and MUST be able to place them on the lesson while a place is free. A placed sign-up MUST be recorded as placed and by whom.

#### Scenario: A coordinator places a learner after the deadline

- **GIVEN** the window of the first Thursday lesson has closed with 12 free places, and learner t.smit did not sign up
- **WHEN** the coordinator opens the lesson, finds t.smit under "Not signed up" and chooses "Place"
- **THEN** t.smit is on the lesson as placed by the coordinator

### Requirement: Every sign-up obeys the same rules whoever writes it

The rules on eligibility, the window, the capacity and one sign-up per learner per lesson MUST be enforced when a sign-up is created or changed through any path: the learner's screen, a coordinator's screen or the object API. Only staff placing a learner may write outside the window; nobody may exceed the capacity.

#### Scenario: A full lesson refuses a coordinator too

<!-- @e2e exclude Creating-event listener; covered by ElectiveSignUpRulesTest::testCapacityHoldsForStaff. -->

- **GIVEN** a lesson with all 24 places taken
- **WHEN** a coordinator places one more learner
- **THEN** the write is refused with the reason that the lesson is full

### Requirement: Another system signs learners up through the API

An account in the group `elective-integrations` MUST be able to create and withdraw sign-ups for any eligible learner through OpenRegister's object API on `elective-sign-up`, and every such write MUST be recorded as made via integration and checked by the same rules.

#### Scenario: A student portal signs a learner up

<!-- @e2e exclude API path with no learniq screen; covered by the integration test of task 5. -->

- **GIVEN** a school's own student app with an account in `elective-integrations`
- **WHEN** it posts a sign-up for learner j.bakker on a lesson with a free place inside the window
- **THEN** the sign-up exists with `madeVia: integration`
- **AND** the same post for a full lesson is refused
