## ADDED Requirements

### Requirement: Signing in writes the mapped fields to the learner's profile

When a user signs in through the school's single sign-on, learniq MUST apply the active `SsoAttributeMapping` rows to the attributes the sign-in sent and write the result to that user's `LearnerProfile`. A field the mappings do not name MUST stay as it was.

#### Scenario: A pupil signs in and their class is filled in

- **GIVEN** an active mapping from the attribute `eduPersonAffiliation` to the profile field `role`
- **AND** a pupil whose profile has no role
- **WHEN** the pupil signs in and the sign-in sends `eduPersonAffiliation` = `student`
- **THEN** the pupil's profile shows the role `student`

#### Scenario: A field without a mapping is left alone

- **GIVEN** a pupil whose profile has a phone number and no mapping names the phone number
- **WHEN** the pupil signs in
- **THEN** the phone number is unchanged
