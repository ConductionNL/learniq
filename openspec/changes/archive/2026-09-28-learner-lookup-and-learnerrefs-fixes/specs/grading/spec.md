# Grading: parent notification lookup delta

## ADDED Requirements

### Requirement: Parent grade notifications find the learner's profile on ncUserId

Publishing a GradeEntry MUST notify every parent in the learner's `LearnerProfile.parentIds`, where the profile is found on `ncUserId`. The lookup MUST NOT depend on the publisher's own read access to LearnerProfile, and MUST NOT pick up another learner's profile.

#### Scenario: The learner's own parents are notified

- **GIVEN** pupil `leerling-001` whose profile lists `ouder-001` and `ouder-002`, and another pupil whose profile lists `ouder-099`
- **WHEN** a grade for `leerling-001` is published
- **THEN** grade notifications are written for `ouder-001` and `ouder-002` only
