# Learning plan: signature guard lookup delta

## ADDED Requirements

### Requirement: Parent co-signs are verified against the learner's profile found on ncUserId

Activating a LearningPlan MUST apply the required signer roles of its template, where the template is found by its id. A `parent` signature MUST count only when its signer is in the `parentIds` of the learner's LearnerProfile, found on `ncUserId`. The profile lookup MUST NOT depend on the signer's own read access to LearnerProfile.

#### Scenario: A co-sign by the learner's parent activates the plan

- **GIVEN** a template requiring a `teacher` and a `parent` signature
- **AND** signatures by the teacher and by `ouder-001`, who is on the learner's profile
- **WHEN** the plan is activated
- **THEN** the guard allows it

#### Scenario: A co-sign by another pupil's parent is refused

- **GIVEN** the same template and a `parent` signature by `ouder-099`, who is not on the learner's profile
- **WHEN** the plan is activated
- **THEN** the guard refuses it

#### Scenario: A plan without the required signatures stays in draft

- **GIVEN** a template requiring signatures and no signatures on the plan
- **WHEN** the plan is activated
- **THEN** the guard refuses it
