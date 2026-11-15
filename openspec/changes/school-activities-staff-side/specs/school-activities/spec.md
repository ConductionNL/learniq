## ADDED Requirements

### Requirement: Staff MUST set an activity's places by capacity and supervision

A term-long activity SHALL have places equal to the lower of its `capacity` and the number of distinct supervisors times `childrenPerSupervisor`. When `childrenPerSupervisor` is 0 or absent, the places SHALL be the capacity alone. Staff SHALL see the places on the activity in learniq. Staff SHALL NOT be able to open an activity that has no place: the answer SHALL be 422 `no_places` and the activity SHALL stay a draft.

#### Scenario: Supervision caps the places
@e2e exclude {a derived number; asserted by a unit test on the places computation when the staff page is built}

- **GIVEN** an activity with `capacity: 20`, two supervisors and `childrenPerSupervisor: 8`
- **WHEN** staff open the activity in learniq
- **THEN** the activity SHALL show 16 places

#### Scenario: An activity without enough supervision does not open
@e2e exclude {a staff refusal; asserted by a controller test when the staff page is built}

- **GIVEN** a draft activity with `childrenPerSupervisor: 8` and no supervisors
- **WHEN** staff open it
- **THEN** the answer SHALL be 422 `no_places`
- **AND** the activity SHALL stay a draft

### Requirement: Where photos are taken, the staff roster MUST show each child's photo consent

When an activity has `photosTaken: true`, every child on its staff roster SHALL carry a photo consent mark, read from the signing guardian's consent for that child (purpose `news`, the channel school photos are shared through). An absent consent SHALL read as no consent. When `photosTaken` is false, the roster SHALL show no photo consent mark.

#### Scenario: The supervisor sees who may not be photographed
@e2e exclude {needs guardian consent fixtures on a live instance; asserted by a unit test on the roster when the staff page is built}

- **GIVEN** an activity with `photosTaken: true` and two confirmed children, one with photo consent on file and one without
- **WHEN** staff open the roster in learniq
- **THEN** the first child SHALL show photo consent yes
- **AND** the second SHALL show photo consent no

### Requirement: Staff MUST be able to mark attendance per session

Staff SHALL be able to mark a child `present`, `absent` or `excused` for one of the activity's declared sessions, only for a child with a confirmed place. A second mark for the same session and child SHALL update the first. An unknown session, a child without a confirmed place and an unknown status SHALL each be refused with 422 and nothing written.

#### Scenario: A second mark corrects the first
@e2e exclude {an upsert on stored rows; asserted by a unit test when the staff page is built}

- **GIVEN** a child marked `absent` for session `week-3`
- **WHEN** staff mark the same child `present` for `week-3`
- **THEN** exactly one attendance row SHALL exist for that child and session, reading `present`

#### Scenario: A waitlisted child cannot be marked present
@e2e exclude {a staff refusal; asserted by a unit test when the staff page is built}

- **GIVEN** a waitlisted child
- **WHEN** staff mark that child present
- **THEN** the answer SHALL be 422 `not_confirmed`
- **AND** nothing SHALL be written
