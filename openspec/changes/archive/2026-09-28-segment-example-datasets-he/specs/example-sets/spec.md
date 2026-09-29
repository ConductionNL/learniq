# Example sets Specification

## ADDED Requirements

### Requirement: The higher education set is one consistent institution
`lib/Settings/profiles/he.json` MUST describe one fictional university of applied sciences through the 2025-2026 academic year: one `School`, two `Vestiging` faculties, four programmes whose required learning outcomes each sit under one of the five Dublin descriptors, courses with ECTS credits (60 in the first year of every programme), a cohort per programme per study year, between 380 and 420 students each in exactly one cohort and enrolled only in courses of that cohort's programme, study advisers on Staff, internship portfolios for every third-year student and a peer reviewed group project. Every final grade MUST equal what `GradeAggregationEngine` computes from the published grade entries of its curriculum plan, and its enrolment MUST be completed when it passed and failed when it did not. Every attendance mark MUST sit on a workgroup session of the student's own cohort, marked by the teacher assigned to that course. A peer reviewer MUST NOT review their own group's submission. Every name, address, institution, company and code MUST be fictional, and no object MAY carry a BSN.

#### Scenario: A final grade is what the engine computes
- **GIVEN** a final grade in the set
- **WHEN** `GradeAggregationEngine::applyFormula()` runs over the published entries of the same learner and curriculum plan
- **THEN** the value and breakdown equal the stored ones, `passed` equals `GradePassEvaluator`'s answer, and the enrolment is completed exactly when it passed

#### Scenario: An absence belongs to the student's own workgroup
- **GIVEN** the set's attendance records
- **WHEN** each is compared with its session, the student's enrolments and the subject teacher assignments
- **THEN** the session's cohort is the student's cohort and `markedBy` is the teacher of that session's course

#### Scenario: A peer reviewer never reviews their own group
- **GIVEN** the group project's peer reviews
- **WHEN** each reviewer is compared with the reviewed submission's `learnerIds`
- **THEN** the reviewer is never a member, and every member's project grade entry names the group's submission

### Requirement: The binding study advice follows the grades
Every first-year student who did not withdraw MUST have exactly one `BsaDecision`; a student who withdrew before the check date MUST have none. `ectsAchieved` MUST equal the ECTS credits of the student's passed final grades on courses of the programme, which is what `BsaProgressEvaluator` sums. A decision MUST be positive exactly when `ectsAchieved` reaches `ectsNormRequired`. A negative decision MUST reference at least one issued or acknowledged `BsaWarning` of the same student, and MUST carry a rationale and the moment the student was heard. A `BsaProgressFlag` MUST record fewer credits than the interim norm it was raised against.

#### Scenario: The advice counts the passed credits
- **GIVEN** a BSA decision
- **WHEN** the ECTS credits of the student's passed final grades in the programme are summed
- **THEN** the sum equals `ectsAchieved`, and the decision is positive exactly when it reaches the norm

#### Scenario: A negative advice follows a warning
- **GIVEN** a negative BSA decision
- **WHEN** its `warningIds` are read
- **THEN** each names an issued or acknowledged warning of the same student, and the decision carries a rationale and `studentHeardAt`

### Requirement: The item bank exams agree with their statistics
Every item an exam references MUST belong to the item bank of that item, every assessment result MUST answer exactly its exam's items and name the grade entry it produced, every `ItemStatistics` row MUST carry the sample size and the share of full-mark responses computed from the stored results, and every `ItemRevisionFlag` MUST be justified by its statistic under the default thresholds. The proctoring session MUST point at a result that points back at it, on an exam that runs in native test mode.

#### Scenario: A p-value matches the stored responses
- **GIVEN** an item statistic of a main sitting
- **WHEN** the full-mark responses to that item are counted over the sitting's results
- **THEN** `sampleSize` equals the number of results and `pValue` equals the full-mark share

### Requirement: The higher education set loads and removes cleanly
The set MUST pass `ExampleSetDescriptorContractTest`, MUST be offered by `SeedProfileService` as "Higher education (HBO or university)" with an `objectCount` equal to the objects it ships, and its removal list (`uuidsFor('he')`) MUST name every object exactly once, the last-loaded first and the institution last. The file MUST equal what `scripts/example-sets/he.py` generates.

#### Scenario: The set is offered with its true size
- **GIVEN** the shipped `he.json`
- **WHEN** the wizard lists the example sets
- **THEN** "Higher education (HBO or university)" appears with an object count equal to the objects in the file

#### Scenario: Removal covers every object once
- **GIVEN** the shipped `he.json`
- **WHEN** `uuidsFor('he')` is read
- **THEN** it holds as many unique uuids as the file has objects, the last-loaded first, the institution last

#### Scenario: The file is reproducible
- **GIVEN** the generator
- **WHEN** `python3 scripts/example-sets/he.py --check` runs
- **THEN** it exits 0
