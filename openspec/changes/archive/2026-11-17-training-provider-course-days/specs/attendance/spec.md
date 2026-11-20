## ADDED Requirements

### Requirement: A trainer registers attendance per part of the day

A course day's session MAY be split into parts (morning, afternoon); attendance MUST then be registered per participant per part, with an arrival time when late, and the trainer's Today MUST name the part that has begun and is not yet registered.

#### Scenario: The afternoon has begun
- **GIVEN** the morning of "Storingen zoeken aan warmtepompen" is registered (8 of 9, Mehmet Kaya at 10.15)
- **WHEN** Henk Dekker opens Today at 13.05
- **THEN** the card reads "De middag is begonnen. Vul de aanwezigheid in." with "Vanochtend waren er 8 van de 9"
- @e2e exclude staff screen; spec-only proposal
