## ADDED Requirements

### Requirement: A pupil reads her school's open support lessons and signs herself up

The student audience MUST declare `studentElectiveOffers` over `elective-offer`, narrowed to open offers of the pupil's own school and year, projecting title, next date and time, place, the teacher's name and a line with the places left, and an action `signUpForElective` that creates an `elective-sign-up` for the signed-in learner only. The action MUST refuse a full offer, an offer outside its window and a second sign-up for the same offer, with the reason in words.

#### Scenario: Noor signs up for Tuesday
- **GIVEN** the support lesson wiskunde A has 6 places left for Tuesday 6 October
- **WHEN** Noor signs up from her wiskunde page
- **THEN** her sign-up exists, the line reads "Nog 5 plekken", and her mentor's page shows the support lesson from 6 October
- @e2e tests/e2e/portal-design/vaartveld.spec.ts

#### Scenario: Full
- **GIVEN** no places are left
- **WHEN** a pupil signs up
- **THEN** the sign-up is refused with "Deze steunles is vol"
- @e2e exclude refusal asserted in the action's unit tests

### Requirement: A pupil may book her own mentor talk when the round allows it

When a conference round sets `pupilMayBook`, the student audience MUST read the free times of that round for her own group's mentor and MUST be able to book one for herself; the booking MUST follow the same atomic claim and lifecycle as a guardian's booking, and the pupil's guardians MUST see the booked time on their portal and receive the booking notice.

#### Scenario: Noor picks Tuesday 16.30
- **GIVEN** mevrouw Kramer's round for H4b allows pupils to book
- **WHEN** Noor books Tuesday 13 October 16.30
- **THEN** the time is hers, her father Erik receives a notice, and his portal shows the talk
- @e2e tests/e2e/portal-design/vaartveld.spec.ts
