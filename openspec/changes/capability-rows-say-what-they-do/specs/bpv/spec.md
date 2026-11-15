## ADDED Requirements

### Requirement: A coordinator links a learner to a training company in a placement

A coordinator MUST be able to create a `BpvPlacement` that names the learner (`learnerId` and `learnerRef`), the training company by name and KvK number, the workplace supervisor (`practicalTrainerId`), the school coach and the period. The placement MUST start as `proposed`. The learner's placement and the supervisor's placements MUST each list it.

#### Scenario: A coordinator places a learner at a company

- **GIVEN** a learner "Milan" and a workplace supervisor at "Bakker Techniek BV", KvK 12345678
- **WHEN** a coordinator creates a placement for Milan at Bakker Techniek BV with that supervisor, his school coach and the period September to January
- **THEN** the placement is stored as `proposed` with the company name, KvK number, supervisor, coach and period
- **AND** the supervisor's page lists the placement

#### Scenario: A placement without a company is refused

- **GIVEN** a coordinator creating a placement
- **WHEN** they save it without a training company name or KvK number
- **THEN** the save is refused because both are required

### Requirement: A school coach writes up a visit or a three-way conversation for a placement

A school coach MUST be able to record a `BpvVisitReport` for a placement with the date, the kind of visit (`voortgangsbezoek`, `tussentijds-gesprek`, `eindgesprek` or `incident`), the people present by role and name, a narrative and the agreed action points. Finalizing the report MUST move it from `draft` to `finalized`.

#### Scenario: A coach records a three-way conversation

- **GIVEN** an active placement of Jesse at Van Dijk Machinebouw
- **WHEN** his school coach records a `tussentijds-gesprek` on 13 October with Jesse, his workplace supervisor and herself present, a narrative and two action points, and finalizes it
- **THEN** the report is `finalized` with the date, kind, attendees, narrative and action points
- **AND** it is linked to Jesse's placement and to his learner profile
