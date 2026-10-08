## ADDED Requirements

### Requirement: An mbo student chooses a keuzedeel inside its window

A school MUST be able to offer keuzedelen per period with code, name, study load, a choice window and places, and a student MUST be able to choose one for herself inside the window; a choice outside the window or for a full offer MUST be refused in words. The student page MUST show the choice state with the window's end.

#### Scenario: Not yet chosen
- **GIVEN** the keuzedelen of period 3 can be chosen until 16 October
- **WHEN** Milan's coach opens his page on 5 October
- **THEN** it reads "Keuzedeel periode 3: Nog niet gekozen, kan tot 16 oktober"
- @e2e exclude staff screen; spec-only proposal

### Requirement: A student's exam progress reads in words

A student MUST carry the number of exam components passed out of those in her exam plan and her next sitting, readable as "{n} van {total} examenonderdelen behaald" and "Volgende: {code} op {date}".

#### Scenario: Milan
- **GIVEN** Milan passed 3 of 11 components and NE-3F-CE is on 3 November
- **WHEN** his page is read
- **THEN** it shows "3 van 11 examenonderdelen behaald" and "Volgende: NE-3F-CE op 3 november"
- @e2e exclude as above
