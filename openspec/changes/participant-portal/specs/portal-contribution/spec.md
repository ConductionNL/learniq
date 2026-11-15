## ADDED Requirements

### Requirement: A participant lands on his next course day

Learniq MUST serve a `participant` audience whose collections are all scoped by the `learnerRef` claim: his enrolments on `learnerRef`, his certificates on `learnerId`. It MUST offer no write. His home page MUST open with the greeting, then his first coming course day as a highlight with the course, the days, the time and the place, then his issued certificates with the expiry, then his later course days. Each participant enrolment of a company booking MUST carry the booking's `firstDay`, `dayLabel`, `timeLabel`, `placeLabel`, `trainerName` and `upcoming`, written by the server.

#### Scenario: Tom on Monday 5 October 2026
- **GIVEN** the training set and Tom's participant account
- **WHEN** he opens Mijn academie on his phone
- **THEN** he reads F-gassen: herhaling en examen, donderdag 8 oktober, 08.30 tot 16.30 uur at the Praktijkhal, then his F-gassen categorie 1 with "Verloopt over 8 weken", then the course of 3, 4 en 10 november
- @e2e tests/e2e/portal-design/warmtepompacademie.spec.ts
