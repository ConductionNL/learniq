# Report card: learner profile lookup delta

## ADDED Requirements

### Requirement: A composed report card carries the learner's profile as learnerRef

When a report period is composed, each ReportCard MUST carry `learnerRef`: the UUID of the learner's LearnerProfile, found on `ncUserId`. A learner without a profile gets `learnerRef: null`, which keeps the card out of the portal.

#### Scenario: The card names the pupil's profile

- **GIVEN** pupil `leerling-001` with LearnerProfile `lp-001` in a cohort of the period
- **WHEN** the period is composed
- **THEN** the pupil's card has `learnerRef: "lp-001"`

#### Scenario: A pupil without a profile stays out of the portal

- **GIVEN** a pupil in the cohort without a LearnerProfile
- **WHEN** the period is composed
- **THEN** the pupil's card has `learnerRef: null`

### Requirement: Report card parent notifications find the learner's profile on ncUserId

Publishing a ReportCard MUST notify every parent in the learner's `LearnerProfile.parentIds`, where the profile is found on `ncUserId`. The lookup MUST NOT depend on the publisher's own read access to LearnerProfile.

#### Scenario: Both parents are notified

- **GIVEN** pupil `leerling-001` whose profile lists `ouder-001` and `ouder-002`
- **WHEN** the pupil's report card is published
- **THEN** one parent notification is written for each of them
