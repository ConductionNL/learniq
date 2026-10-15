## ADDED Requirements

### Requirement: An employer sends a colleague in place of a participant, before the deadline

The employer audience MUST offer `substituteParticipant` on a participant row of her own organisation's booking, replacing that participant by another person of her organisation while keeping the booking's place, until the course day's substitution deadline. The replaced participant's enrolment MUST be withdrawn with the reason in words and the new participant's enrolment created; after the deadline the action MUST be refused in words.

#### Scenario: Sanne goes instead of Tom
- **GIVEN** Linda's booking I-2026-0412 for Thursday 8 October, deadline Wednesday 12.00
- **WHEN** she replaces Tom by Sanne Kok on Monday
- **THEN** the booking still holds three places, Tom's enrolment reads "vervangen door collega" and Sanne is enrolled
- @e2e tests/e2e/portal-design/warmtepompacademie.spec.ts

#### Scenario: Too late
- **GIVEN** it is Wednesday 12.30
- **WHEN** Linda tries to replace a participant
- **THEN** it is refused with "Vervangen kan tot woensdag 12.00 uur. Bel de planning."
- @e2e exclude refusal asserted in the action's unit tests
