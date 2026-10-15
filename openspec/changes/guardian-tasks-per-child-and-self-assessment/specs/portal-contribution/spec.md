## ADDED Requirements

### Requirement: The guardian reads one task per child with the child's name

The guardian's overview MUST list one task per child of hers per round that child may still book a time in, read through the same child join as every parent read. The title MUST name the child: "Kies een tijd voor het oudergesprek van Sami". The task's button MUST open a page with the invitation and both booking forms.

#### Scenario: Fatima's overview
- **GIVEN** Fatima is the guardian of Vera, who has a time, and Sami, who has none
- **WHEN** she opens her overview
- **THEN** "Wat u nog moet doen" reads "Kies een tijd voor het oudergesprek van Sami", once, and nothing for Vera
- @e2e exclude covered by PHPUnit `GuardianTasksAndSelfAssessmentTest::testTheGuardianTaskIsOnePerChildAndNamesTheChild`

### Requirement: The student fills in her own estimate per work process

A student MUST be able to fill in her own estimate (`selfAssessment`) on her own work process rows, and nothing else: not the hours, not another student's row, not the trainer's judgement.

#### Scenario: Nu invullen
- **GIVEN** Milan's work process B1-K2-W1 has no estimate yet
- **WHEN** he chooses "Nu invullen" and picks "Voldoende"
- **THEN** only `selfAssessment` of his own row is written
- @e2e exclude covered by PHPUnit `GuardianTasksAndSelfAssessmentTest::testSheFillsInOnlyHerOwnEstimate`

### Requirement: The next-step card opens her self-assessment

The "Volgende stap" card on the student's placement page MUST carry a button "Zelfbeoordeling afmaken" that opens her self-assessment of that placement: her work processes with the hours and her estimate, each with "Nu invullen".

#### Scenario: Milan finishes his self-assessment
- **GIVEN** Milan's placement page shows "Volgende stap"
- **WHEN** he chooses "Zelfbeoordeling afmaken"
- **THEN** his work processes of that placement open, with the hours and his estimate
- @e2e exclude covered by PHPUnit `GuardianTasksAndSelfAssessmentTest::testTheNextStepOpensHerSelfAssessment`
