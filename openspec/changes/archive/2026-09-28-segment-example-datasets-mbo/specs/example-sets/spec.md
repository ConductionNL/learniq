# Example sets Specification

## ADDED Requirements

### Requirement: The vocational college set is one consistent college
`lib/Settings/profiles/mbo.json` MUST describe one fictional MBO college through the 2025-2026 school year: one `School`, two `Vestiging` locations, three `Programme` objects at level `mbo` (Logistiek medewerker, Verzorgende IG, Software developer) each with a `CompetencyFramework` whose `sourceAuthority` is `sbb-kwalificatiedossier`, a class per programme and leerjaar (LOG2-1A, LOG2-2A, VIG3-1A, VIG3-2A, VIG3-3A, SD4-1A, SD4-2A, SD4-3A), between 230 and 270 students each with exactly one enrolment in a class that lists them, and a stagecoordinator on `Staff`. No lesson (`Session`) MAY fall in a holiday or on a study day the semester periods list, or on a day the student of that class has a placement visit or assessment. Every attendance mark MUST sit on a lesson of the student's own class and be marked by a teacher assigned to that class and unit who works that weekday. Every name, address, college, company and code MUST be fictional, and no object MAY carry a BSN.

#### Scenario: An absence belongs to the lesson's own teacher
- **GIVEN** the set's attendance records
- **WHEN** each is compared with its session, the student's enrolment and the class's subject teacher assignments
- **THEN** the session's class is the student's class, `markedBy` is assigned to that class and unit, and that teacher's `workingDays` include the weekday

#### Scenario: A placement day carries no lesson
- **GIVEN** a work placement visit report or werkproces assessment
- **WHEN** the lessons of the student's class are listed
- **THEN** none falls on that date

### Requirement: Work placements are signed, visited and assessed
Every `BpvPlacement` MUST have one `Praktijkovereenkomst` over the same period, signed by the student, the school and the placement's praktijkopleider before the period starts. Every `BpvVisitReport` MUST fall inside its placement's period and be made by the placement's BPV-docent on one of that docent's working days. Every `WerkprocesAssessment` MUST fall inside a completed placement's period, be made by the placement's praktijkopleider against the placement's curriculum plan, and name a werkproces `Competency` of the student's own programme whose code equals `werkprocesCode`. Each PVB result (`GradeEntry` graded by `praktijkopleider`) MUST carry the value of the last confirmed assessment of its component, as `WerkprocesGradeEmitHandler` writes it.

#### Scenario: A placement is signed before it starts
- **GIVEN** a praktijkovereenkomst
- **WHEN** its signatures are read
- **THEN** there is one per role (student, school, praktijkopleider), the student and praktijkopleider are the placement's own, and each is dated before `periodFrom`

#### Scenario: A PVB result follows the last assessment
- **GIVEN** a student's werkproces assessments for one PVB component
- **WHEN** the latest one is `competent`
- **THEN** the PVB grade entry for that component has value 1.0, otherwise 0.0

### Requirement: Results and study advice agree with the engines
Every `FinalGrade` MUST equal what `GradeAggregationEngine` and `GradePassEvaluator` compute from the learner's published grade entries for that plan, and every learner with a published entry for a plan MUST have one. Every first-year student MUST have one `BsaDecision` whose `ectsAchieved` equals the credits of the programme's courses with a passed final grade (as `BsaProgressEvaluator` counts them). A negative decision MUST reference an issued or acknowledged `BsaWarning` of the same student and MUST leave the enrolment `withdrawn`; a positive decision MUST meet `ectsNormRequired`. A granted `ExemptionCase` MUST point at an exemption grade entry for the same learner and component, and a proven `FraudCase` at an invalidated entry followed by a published resit.

#### Scenario: A final grade is what the engine computes
- **GIVEN** a learner's published grade entries for one curriculum plan
- **WHEN** the grade engine applies the plan's formula and pass rules
- **THEN** its value, passed flag and breakdown equal the set's final grade

#### Scenario: A negative advice follows a warning
- **GIVEN** a first-year student with a negative decision
- **WHEN** its `warningIds` are resolved
- **THEN** each is an issued or acknowledged warning for that student, and the student's enrolment is `withdrawn`

### Requirement: The vocational college set loads and removes cleanly
The set MUST pass `ExampleSetDescriptorContractTest`, MUST be offered by `SeedProfileService` as "Vocational education (MBO)" with an `objectCount` equal to the objects it ships, and its removal list (`uuidsFor('mbo')`) MUST name every object exactly once, the last-loaded first and the college last. The file MUST equal what `scripts/example-sets/mbo.py` generates.

#### Scenario: The set is offered with its true size
- **GIVEN** the shipped `mbo.json`
- **WHEN** the wizard lists the example sets
- **THEN** "Vocational education (MBO)" appears with an object count equal to the objects in the file

#### Scenario: The file is reproducible
- **GIVEN** the generator
- **WHEN** `python3 scripts/example-sets/mbo.py --check` runs
- **THEN** it exits 0
