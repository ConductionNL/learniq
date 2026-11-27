## ADDED Requirements

### Requirement: Learner answers an evaluation

The system MUST list the caller's open evaluation invitations and MUST let the caller answer each once through a form built from the campaign questions. Submitting MUST create a `CourseEvaluationResponse` through the guarded `submit` transition and mark the invitation `hasResponded`. When the campaign anonymity policy is anonymous, the stored response MUST NOT contain the learner's identity and staff MUST NOT be able to link a response to a learner.

#### Scenario: A learner answers an invitation

- **GIVEN** a learner with one open invitation on My evaluations
- **WHEN** the learner answers every question and submits
- **THEN** a response is stored, the invitation shows as responded and the page no longer lists it

#### Scenario: A learner cannot answer twice

- **GIVEN** a learner who already responded
- **WHEN** the learner posts a second response
- **THEN** the guard denies it

#### Scenario: An uninvited user is refused

- **GIVEN** a learner with no invitation for the campaign
- **WHEN** the learner posts a response
- **THEN** the guard denies it

#### Scenario: Anonymous answers cannot be linked

- **GIVEN** an anonymous campaign with three responses
- **WHEN** a staff member reads the response objects
- **THEN** no field identifies a learner

### Requirement: Campaign results for staff

The system MUST show staff the number of invitations, the number of responses and the mean overall score of a campaign, and MUST hide a mean when fewer than five responses exist.

#### Scenario: Small groups are protected

- **GIVEN** a campaign with three responses
- **WHEN** staff open the results
- **THEN** the response count shows 3 and the mean is hidden
