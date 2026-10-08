# example-sets Specification

## Purpose
Let an administrator start learniq with realistic, fictional example data for the kind of organisation they run. The setup wizard asks which segment this is (primary school, secondary school, MBO, HBO/WO, company or training institute), offers one shipped example set per segment under `lib/Settings/profiles/`, loads exactly the objects its descriptor declares, and can remove them again. The descriptor contract lives in `openspec/changes/archive/2026-09-28-segment-wizard-choice/contract.md`.

## Requirements

### Requirement: An example set is one descriptor file per segment
Each example set MUST be one file `lib/Settings/profiles/<id>.json`, where `<id>` is one of the six segment codes (`po`, `vo`, `mbo`, `he`, `corporate`, `training`) and equals `x-openregister.profile.id` and `x-openregister.profile.segment`. The descriptor MUST declare `x-openregister.type: profile`, MUST NOT declare `components.registers` or `components.schemas`, and MUST carry its objects in `x-openregister.seedData.objects`, keyed by learniq schema slug. Every object MUST carry `@self` with `configuration`, `register` (`learniq`) and `schema` (the bucket key), a fixed `uuid` inside the set's namespace, and a `slug`. Every property holding a reference to another object MUST hold the fixed `uuid` of an object in the same set, or null. A set MUST NOT carry `LearniqSettings` objects.

#### Scenario: A descriptor that follows the contract passes the contract test
- **GIVEN** a file `lib/Settings/profiles/po.json` that follows the contract
- **WHEN** `ExampleSetDescriptorContractTest` runs
- **THEN** every check passes: keys, id, namespace, unique uuids, resolved references, declared object count, schema-valid objects

#### Scenario: A dangling reference fails the contract test
- **GIVEN** a set whose `Enrolment.cohortId` names a uuid no object in the set carries
- **WHEN** the contract test runs
- **THEN** it fails and names the object, the property and the missing uuid

### Requirement: The wizard lists the shipped sets next to the generated one
`SeedProfileService::listChoices()` MUST return `none` first, then every readable descriptor in `order`, then the generated set (`demo`) when `learniq_mock_register.json` ships. The setup status document MUST carry that list as `profiles`, and the `example-set` choice step MUST read it through `optionsSource: profiles`. A malformed descriptor MUST be skipped with a log line, never make the other sets unreachable.

#### Scenario: Two sets on disk
- **GIVEN** `po.json` (order 1) and `vo.json` (order 2) under `lib/Settings/profiles/`
- **WHEN** the setup status is requested
- **THEN** `profiles` lists `none`, `po`, `vo`, `demo` in that order

#### Scenario: A broken file does not hide the others
- **GIVEN** `vo.json` is not valid JSON
- **WHEN** the sets are listed
- **THEN** `po` and `demo` are still listed and a warning is logged

### Requirement: Loading a set imports exactly its descriptor
The `load-example-set` action MUST import the set stored in `example_profile` through OpenRegister's `ConfigurationService::importFromApp()` with the config id `learniq.profile.<id>`, report the object count it asked for, and answer `none` as a finished decision. An unknown or missing answer MUST be refused, never replaced by a default. The id MUST be resolved by reading the files, never by building a path from the request.

#### Scenario: Loading the primary school set
- **GIVEN** `example_profile` is `po`
- **WHEN** `load-example-set` runs
- **THEN** `importFromApp` receives the `po` descriptor under config id `learniq.profile.po`
- **AND** the answer names the object count

#### Scenario: A path in the answer is refused
- **GIVEN** a request posting `example_profile: "../../config/config"`
- **WHEN** the answer is saved
- **THEN** it is refused with HTTP 400

### Requirement: A loaded set can be removed exactly
`occ learniq:example-set:remove <id>` MUST hand exactly the fixed uuids that `<id>.json` declares, in reverse load order, to OpenRegister's `openregister:objects:purge` with `--force`, and MUST pass `--apply` only when it was given `--apply` itself (dry run by default). It MUST NOT delete anything itself and MUST NOT offer an HTTP route: three school schemas are archival, and OpenRegister keeps the shell as the one deliberate exit for them. The generated set has no fixed uuids and MUST be refused with an explanation, as MUST an unknown id and an instance without OpenRegister's purge command.

#### Scenario: Removing the primary school set
- **GIVEN** the `po` set was loaded
- **WHEN** `occ learniq:example-set:remove po --apply` runs
- **THEN** `openregister:objects:purge` receives every uuid in `po.json`, last-loaded first, with `--force` and `--apply`
- **AND** no other uuid

#### Scenario: A dry run changes nothing
- **GIVEN** the `po` set was loaded
- **WHEN** `occ learniq:example-set:remove po` runs without `--apply`
- **THEN** the purge command runs without `--apply` and only reports what it would purge

#### Scenario: The generated set is refused
- **GIVEN** the generated set `demo`
- **WHEN** `occ learniq:example-set:remove demo` runs
- **THEN** it exits non-zero and explains that only sets with fixed uuids can be removed

### Requirement: The wizard asks what kind of organisation this is
The setup wizard MUST offer a `segment` choice step titled "What kind of organisation is this?" with six single-select cards from the status document's `segments` list: primary school, secondary school, MBO, HBO/WO, company, training institute. Saving it MUST write `LearniqSettings.segment` (creating the record when none exists, otherwise updating the current one, stamping `setBy` and `setAt`). The step MUST report done once a valid segment is stored, and MUST pre-select the segment of the example set picked earlier. `welcome` MUST stay step 1 and `example-set` step 2.

#### Scenario: A school picks primary school
- **GIVEN** no `LearniqSettings` record exists
- **WHEN** the admin picks "Primary school" and continues
- **THEN** a `LearniqSettings` record with `segment: po`, `setBy` the admin's user id and `setAt` now is created
- **AND** the status reports the `segment` step done

#### Scenario: The primary school example set pre-selects primary school
- **GIVEN** the admin picked the `po` example set
- **WHEN** the `segment` step opens
- **THEN** "Primary school" is pre-selected

#### Scenario: An unknown segment is refused
- **GIVEN** a request posting `segment: "kindergarten"`
- **WHEN** the answer is saved
- **THEN** it is refused with HTTP 400 and nothing is written

### Requirement: The company set is one consistent company
`lib/Settings/profiles/corporate.json` MUST describe one fictional company through the 2025-2026 training year: one `School` record for the company, two `Vestiging` sites, eight department cohorts ("Directie en staf", "Financiën en administratie", "ICT", "Verkoop en klantenservice", "Planning en werkvoorbereiding", "Installatie en service", "Magazijn en logistiek", "Werkplaats"), and between 190 and 210 employee profiles. Every employee MUST sit in exactly one department cohort, MUST have a manager in the set (the director excepted), and MUST be enrolled in the code of conduct and the information security courses. An HR adviser and a compliance officer MUST be on Staff. Every attendance mark MUST sit on a session of its own cohort, every session on a working day of the year that is not a public holiday, no room MAY be booked twice at once, and no employee MAY be present in two sessions at once. Every name, address, company and certificate MUST be fictional: credentials carry the signature "voorbeeldgegevens-niet-ondertekend" and an issuer DID on the reserved `.example` domain, and no object MAY carry a BSN.

#### Scenario: A mark belongs to a session of its own cohort
- **GIVEN** the set's attendance records
- **WHEN** each is compared with its session and the session's cohort
- **THEN** the cohort is the same, the employee is on its roster, and the session falls on a working day inside the year

#### Scenario: Every employee is enrolled somewhere
- **GIVEN** any employee profile
- **WHEN** their enrolments are read
- **THEN** they include the code of conduct course and the information security course

### Requirement: Certificates expire and renew the way the listener does it
A credential whose `expiresAt` falls on or before 2026-07-10 MUST be `expired`, every other credential `issued`. A credential that expires inside the year MUST name a `renewalEnrolmentId` whose enrolment has `source: credential-renewal`, the same learner, and the course that the expired credential's course names as `renewalCourseSlug`. A completed renewal MUST have issued exactly one new credential, issued after the old one expired. Everyone a certification applies to (VCA for Operatie, NEN 3140 for Installatie en service and Werkplaats, the forklift certificate for Magazijn en logistiek) MUST hold a current credential for it or have an open enrolment for it.

#### Scenario: An expired certificate opened its renewal
- **GIVEN** a BHV certificate issued in September 2024 for twelve months
- **WHEN** the set is read
- **THEN** the certificate is `expired`, names a renewal enrolment in "BHV herhaling", and the employee attended a herhaling day after the expiry and holds a new certificate issued at the end of that day

#### Scenario: The year ends with renewals still open
- **GIVEN** a NEN 3140 certificate that expires after the last herinstructie session of the year
- **WHEN** the set is read
- **THEN** its renewal enrolment is still active and no new certificate exists for it

### Requirement: Skills gaps, plans, points and payments agree with their sources
The skills gap computed the way `SkillsGapDashboard.vue` computes it (programme requirements of enrolled courses plus competencies required for the employee's roles, minus attainments with a proficiency level) MUST have an open goal in that employee's development plan for every gap, and a DIG-01 gap MUST mean the information security course is unfinished. Every plan MUST be coordinated by the employee's manager (HR for the director), and every mid-year review MUST be held by that coordinator and cover every goal of the plan. The set MUST carry one point award per completed enrolment, each employee's `totalPoints` MUST equal the sum of their awards and their level the highest one that total reaches, and an engagement risk flag MUST follow an unfinished course. A paid order MUST have exactly one successful payment and an active entitlement, an open order a pending entitlement and a pending enrolment, a cancelled order no entitlement and a withdrawn enrolment.

#### Scenario: A gap is on the plan
- **GIVEN** a service technician without an attainment for "Warmtepompen installeren en in bedrijf stellen"
- **WHEN** their development plan is read
- **THEN** it has an open goal that starts with that competency's title

#### Scenario: The leaderboard adds up
- **GIVEN** an employee's point awards
- **WHEN** their `LearnerEngagement` row is read
- **THEN** `totalPoints` is the sum of the awards and `levelId` is the highest level whose `minPoints` that total reaches

### Requirement: The company set loads and removes cleanly
The set MUST pass `ExampleSetDescriptorContractTest`, MUST be offered by `SeedProfileService` as "Company" with an `objectCount` equal to the objects it ships, and its removal list (`uuidsFor('corporate')`) MUST name every object exactly once, the last-loaded first and the company last. The file MUST equal what `scripts/example-sets/corporate.py` generates.

#### Scenario: The set is offered with its true size
- **GIVEN** the shipped `corporate.json`
- **WHEN** the wizard lists the example sets
- **THEN** "Company" appears with an object count equal to the objects in the file

#### Scenario: The file is reproducible
- **GIVEN** the generator
- **WHEN** `python3 scripts/example-sets/corporate.py --check` runs
- **THEN** it exits 0

### Requirement: The register no longer carries the dark corporate seed
The `ExternalTrainingRecord` `x-openregister-seed` block MUST be empty, and the promoted row ("NIS2 board awareness session") MUST live in the set as a verified external record with regulation `NIS2` for the director, found by title.

#### Scenario: The promoted row lives in the set
- **GIVEN** the register and the set
- **WHEN** both are read
- **THEN** the register's `ExternalTrainingRecord` seed is empty and the set holds the NIS2 board awareness session for `corporate-directeur-01`

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

### Requirement: The training institute set is one consistent institute
`lib/Settings/profiles/training.json` MUST describe one fictional training institute through the 2025-2026 year: one `School` with a code that ends in a digit, one `Vestiging`, at least twelve published courses each with a `FeeItem` price, between 130 and 170 participants (`roles: ["learner"]`) from at least five client companies, each with at least one enrolment in an edition (`cohortId`) of a course, and trainers on `Staff`. Participants MUST carry no guardian (`guardianRefs` and `parentIds` empty), no BSN and no ECK iD. Every attendance mark MUST sit on a held session of an edition the participant is enrolled in for that session's course, marked by the trainer assigned to that edition or the session's stand-in. No trainer and no room MAY be booked for two sessions at the same time, and every session MUST fall on a weekday of the school year. A cancelled session MUST carry its reason and no marks. Every name, address, company and code MUST be fictional.

#### Scenario: A mark belongs to an enrolled participant and the trainer on duty
- **GIVEN** the set's attendance records
- **WHEN** each is compared with its session, the enrolments and the edition's teacher assignment
- **THEN** the session was held, the participant is enrolled in that edition for that course, and `markedBy` is the assigned trainer or the session's `substituteTeacherId`

#### Scenario: Nobody is in two places at once
- **GIVEN** every held session
- **WHEN** sessions are grouped by trainer and by room per start time
- **THEN** no group holds more than one session

### Requirement: Certificates, evaluations and waiting lists agree with what happened
A certificate or badge with `source: auto` MUST follow a completed enrolment of the same course and learner. An enrolment in a regulated course (a course with a `regulationSlug`) MUST only be completed when the participant attended every session of the edition, and MUST then carry a signed attestation. An enrolment in an edition with a knowledge test MUST only be completed after an attempt scoring at least the pass mark; a failed enrolment MUST show every attempt below it. Withdrawn and failed enrolments MUST earn nothing and MUST say why. A participant rebooked after missing an edition MUST have an enrolment in a later edition of the same course. Every migrated certificate MUST have expired before the first session of the renewal enrolment it names. Converted applications MUST name the participant and the `admission` enrolments they created, placements per intake round MUST stay within its capacity, and the programme MUST keep a waiting list. Course evaluation responses MUST match, per campaign, course and edition, the invitations that say they responded; each course quality score MUST count exactly those invitations and responses and carry their mean. Every improvement action MUST answer a measured score. Every package import report MUST account for each resource once, and an export read back in MUST become a draft course.

#### Scenario: No certificate without the full edition and a passed test
- **GIVEN** a completed enrolment in BHV basis
- **WHEN** its marks and knowledge test results are read
- **THEN** no mark is an absence, one attempt reaches the pass mark, a certificate names the enrolment and a signed attestation names the learner and course

#### Scenario: The quality score counts the evaluations
- **GIVEN** the course quality score of a course in a quarter
- **WHEN** the invitations and responses of that course and quarter are counted
- **THEN** `invitationCount`, `responseCount` and `averageOverallScore` equal the counts and the mean

#### Scenario: An improvement action shows in the next quarter
- **GIVEN** the improvement actions for "Werken met spreadsheets, gevorderd" and "Heftruckchauffeur" in quarter 1
- **WHEN** their quality scores of quarter 1 and quarter 2 are compared
- **THEN** quarter 2 scores higher

### Requirement: The training institute set loads and removes cleanly
The set MUST pass `ExampleSetDescriptorContractTest`, MUST be offered by `SeedProfileService` as "Training institute" with an `objectCount` equal to the objects it ships, and its removal list (`uuidsFor('training')`) MUST name every object exactly once, the last-loaded first and the institute last. The file MUST equal what `scripts/example-sets/training.py` generates.

#### Scenario: The set is offered with its true size
- **GIVEN** the shipped `training.json`
- **WHEN** the wizard lists the example sets
- **THEN** "Training institute" appears with an object count equal to the objects in the file

#### Scenario: Removal covers every object once
- **GIVEN** the shipped `training.json`
- **WHEN** `uuidsFor('training')` is read
- **THEN** it holds as many unique uuids as the file has objects, the last-loaded first, the institute last

#### Scenario: The file is reproducible
- **GIVEN** the generator
- **WHEN** `python3 scripts/example-sets/training.py --check` runs
- **THEN** it exits 0

### Requirement: The secondary school set is one consistent school
`lib/Settings/profiles/vo.json` MUST describe one fictional havo/vwo school through the 2025-2026 school year: one `School` with a BRIN ending in a digit, two `Vestiging` locations, eleven classes covering leerjaar 1 to 6 (a havo/vwo brugklas, then havo 2 to 5 and vwo 2 to 6), between 250 and 350 pupils each with at least one guardian profile (`roles: ["parent"]`) and exactly one enrolment on a class of their own leerjaar, a mentor and subject teachers per class (`SubjectTeacherAssignment`), a decaan and a zorgcoördinator on `Staff`, and three report periods with a report card per pupil per period. Every attendance mark MUST sit on a session of the pupil's own class, on a school day on or after the pupil's inschrijving; an absence MUST be marked by the attendance desk and a late arrival by a teacher of that class who works that weekday. No session MAY fall in a holiday or on a study day the report periods list, and no exam class session MAY fall after its last lesson day. Every report card's `attendanceSummary` MUST count exactly the marks of its period. Every name, address, school and code MUST be fictional, and no object MAY carry a BSN.

#### Scenario: A late arrival belongs to the pupil's own class and a teacher on duty
- **GIVEN** the set's attendance records
- **WHEN** each is compared with its session, the pupil's enrolment, the class's teachers and the staff working days
- **THEN** the session's class is the pupil's class, the day is a weekday on or after the inschrijving, and a `late` mark is marked by a teacher of that class whose `workingDays` include that weekday

#### Scenario: The report card and the attendance list agree
- **GIVEN** a pupil's report card for periode 2
- **WHEN** the pupil's marks between the period's start and end are counted per status
- **THEN** the counts equal the card's `absentExcusedCount`, `absentUnexcusedCount`, `lateCount` and `leftEarlyCount`

### Requirement: Grades agree with the records derived from them
Every `GradeEntry` MUST score a component its `CurriculumPlan` declares, in the period that component names, on a session of the pupil's own class that falls on a toetsweek day. Every `FinalGrade` MUST equal the weighted average of that pupil's grade entries on its plan, with a `breakdown` of per-period averages and per-component contributions shaped as `GradeAggregationEngine` computes them. Every exam class pupil MUST have a `FinalGrade` on a PTA plan (`kind: pta`) for each subject in their package. A report card subject line with a `FinalGrade` behind it MUST carry that grade's period average and list exactly the grade entries of that period.

#### Scenario: An SE final grade is the weighted average of its SE grades
- **GIVEN** a havo 5 pupil's final grade for Nederlands on the PTA plan
- **WHEN** the pupil's grade entries on that plan are averaged with the plan's component weights
- **THEN** the result equals the final grade's `value`, and `breakdown.periods` holds the average per period

#### Scenario: The report card shows the period average of the stored grades
- **GIVEN** an exam class pupil's report card for periode 1
- **WHEN** its subject line for a PTA plan is compared with the final grade and the grade entries
- **THEN** `periodAverage` equals `breakdown.periods["1"]` and `sourceGradeEntryIds` are the pupil's entries on that plan in period 1

### Requirement: The school's own records tell the secondary school story
The set MUST carry a `SubjectChoice` for every leerjaar 3 pupil against the havo or vwo package plan for 2026-2027; an approved choice MUST satisfy that plan's `electiveRules`, and a choice in `needs-revision` MUST list the validator's message for the rule it breaks. It MUST carry exactly one `SchoolAdvies`, for a brugklas pupil, whose converted `Application` points at that pupil's profile and enrolment. It MUST carry a leerplicht threshold with at least one `AttendanceFlag` whose breaching records are that pupil's unexcused absences in the window and add up to more than 16 hours, and an attendance-percentage flag whose value equals that pupil's report card percentage for the period.

#### Scenario: An approved profielkeuze satisfies the package rules
- **GIVEN** an approved leerjaar 3 subject choice
- **WHEN** its selected electives are checked against the plan's `minElectives`, `maxElectives` and `mutuallyExclusive` rules
- **THEN** no rule is broken

#### Scenario: The incoming schooladvies belongs to a brugklas pupil
- **GIVEN** the set's one `SchoolAdvies`
- **WHEN** its learner is looked up
- **THEN** the learner is enrolled in a brugklas, and a converted application names that learner's profile and enrolment

### Requirement: The secondary school set loads and removes cleanly
The set MUST pass `ExampleSetDescriptorContractTest`, MUST be offered by `SeedProfileService` with an `objectCount` equal to the objects it ships, and its removal list (`uuidsFor('vo')`) MUST name every object exactly once, the last-loaded first and the school last. The file MUST equal what `scripts/example-sets/vo.py` generates.

#### Scenario: The set is offered with its true size
- **GIVEN** the shipped `vo.json`
- **WHEN** the wizard lists the example sets
- **THEN** "Secondary school" appears with an object count equal to the objects in the file

#### Scenario: The file is reproducible
- **GIVEN** the generator
- **WHEN** `python3 scripts/example-sets/vo.py --check` runs
- **THEN** it exits 0

### Requirement: The primary school set is one consistent school
`lib/Settings/profiles/po.json` MUST describe one fictional primary school through the 2025-2026 school year: one `School`, two `Vestiging` locations, seven classes named "Groep 1" to "Groep 8" with a combined "Groep 5/6", between 180 and 220 pupils each with at least one guardian profile (`roles: ["parent"]`) and one enrolment, staff with subject assignments, two report periods with report cards, LVS results, dossier notes and support requests. Every attendance mark MUST sit on a session of the pupil's own class, on a school day on or after the pupil's inschrijving, marked by the teacher whose `teacherAssignments.days` cover that weekday. No session MAY fall in a holiday or on a study day the report periods list. Every report card's `attendanceSummary` MUST count exactly the marks of its period. Every name, address, school and code MUST be fictional, and no object MAY carry a BSN.

#### Scenario: An absence belongs to the pupil's own class and teacher
- **GIVEN** the set's attendance records
- **WHEN** each is compared with its session, the pupil's enrolment and the class's teacher assignments
- **THEN** the session's class is the pupil's class, the day is a weekday on or after the inschrijving, and `markedBy` is the teacher on duty that weekday

#### Scenario: The report card and the attendance list agree
- **GIVEN** a pupil's report card for Rapport 1
- **WHEN** the pupil's marks between the period's start and end are counted per status
- **THEN** the counts equal the card's `absentExcusedCount`, `absentUnexcusedCount`, `lateCount` and `leftEarlyCount`

#### Scenario: The curated seed lives on in the set
- **GIVEN** the register's former `x-openregister-seed` rows (De Wilgenboom, "Groep 5/6" with its duo split, "Groep 7" notes, Herfstvakantie, the technisch lezen group plan)
- **WHEN** the set is read
- **THEN** each is present, found by name, with fictional names and codes

### Requirement: The primary school set loads and removes cleanly
The set MUST pass `ExampleSetDescriptorContractTest`, MUST be offered by `SeedProfileService` with an `objectCount` equal to the objects it ships, and its removal list (`uuidsFor('po')`) MUST name every object exactly once, the last-loaded first and the school last. The file MUST equal what `scripts/example-sets/po.py` generates.

#### Scenario: The set is offered with its true size
- **GIVEN** the shipped `po.json`
- **WHEN** the wizard lists the example sets
- **THEN** "Primary school" appears with an object count equal to the objects in the file

#### Scenario: Removal covers every object once
- **GIVEN** the shipped `po.json`
- **WHEN** `uuidsFor('po')` is read
- **THEN** it holds as many unique uuids as the file has objects, the last-loaded first, the school last

#### Scenario: The file is reproducible
- **GIVEN** the generator
- **WHEN** `python3 scripts/example-sets/po.py --check` runs
- **THEN** it exits 0

### Requirement: The register no longer carries dark primary school seeds
The ten `x-openregister-seed` blocks promoted into the set (`School`, `Vestiging`, `Cohort`, `Enrolment`, `ReportPeriod`, `GroupPlan`, `GroupPlanSubgroup`, `GroupPlanEvaluation`, `Staff`, `SubjectTeacherAssignment`) MUST be empty, and the tests that read them MUST read the set instead, finding rows by name or uuid and asserting floors.

#### Scenario: A seed test reads the set
- **GIVEN** `SchoolAndLocationRegisterTest` and the five other repointed tests
- **WHEN** they run
- **THEN** they read `lib/Settings/profiles/po.json` and pass

### Requirement: A schema with its own slug pattern takes the slug from the object
When a learniq schema declares a `pattern` on its own `slug` property, an example object of that schema MUST use its own `slug` as the envelope slug: the value MUST match the schema's pattern and MUST be unique within the descriptor, and the `<id>-<schema>-<NNN>` form MUST NOT be required of it. Every other contract rule, including the fixed `uuid` inside the set's namespace, MUST still apply. For a schema whose `slug` property has no pattern, or that has no `slug` property, the `<id>-<schema>-<NNN>` form MUST still apply. `ExampleSetDescriptorContractTest` MUST enforce both branches.

#### Scenario: A Regulation row with its code as slug passes
- **GIVEN** a descriptor with a `regulation` object whose `slug` is `VCA` and whose `uuid` is inside the set's namespace
- **WHEN** the contract test runs
- **THEN** it reports no finding for that object

#### Scenario: A Regulation row with the envelope form fails the pattern
- **GIVEN** a `regulation` object whose `slug` is `corporate-regulation-001`
- **WHEN** the contract test runs
- **THEN** it reports that `slug` does not match `^[A-Z0-9_-]+$`

#### Scenario: Two rows with the same code
- **GIVEN** two `regulation` objects whose `slug` is `VCA`
- **WHEN** the contract test runs
- **THEN** it reports the second one as not unique

### Requirement: A set does not re-ship a row the register seeds
An example object of a schema with its own slug pattern MUST NOT reuse a `slug` that `learniq_register.json` already seeds for that schema, because the importer matches seed objects by `uuid` and would create a second row with the same identifier. The contract test MUST report such an object.

#### Scenario: A set that ships AVG
- **GIVEN** the register seeds the regulation `AVG`
- **WHEN** a descriptor ships a `regulation` object with `slug` `AVG`
- **THEN** the contract test reports that the register already seeds it

### Requirement: The company and training sets carry the regulations they reference
The company set MUST ship a published, active Regulation row for every `regulationSlug` its objects carry, except the ones the register seeds, and so MUST the training set. Each row's audience MUST describe who that set actually trains: for the company, `department` scopes for VCA (`Operatie`), NEN 3140 (`Operatie/Installatie en service`, `Operatie/Werkplaats`) and the forklift certificate (`Operatie/Magazijn en logistiek`); `all-employees` for the code of conduct and information security; `board` with the `manager` and `compliance-officer` roles for NIS2; and an empty `role-specific` audience for BHV and F-gassen, whose obligation falls on designated people. The training institute obliges none of its participants, so its rows carry an empty `role-specific` audience and describe the certificate they lead to. `profile.objectCount` MUST equal the real count.

#### Scenario: Every company regulation reference resolves
- **GIVEN** the company set
- **WHEN** every `regulationSlug` in it is collected
- **THEN** each one is the `slug` of a Regulation row in the set, or `AVG`

#### Scenario: The company scopes drive the certification check
- **GIVEN** the company set's Regulation rows with a `department` audience
- **WHEN** `CorporateExampleSetTest` checks that everyone in scope holds the certificate or is booked on it
- **THEN** it reads the scopes from those rows, and they include VCA, NEN 3140 and the forklift certificate

#### Scenario: Every training regulation reference resolves
- **GIVEN** the training set
- **WHEN** every `regulationSlug` in it is collected
- **THEN** each one is the `slug` of a Regulation row in the set, or `AVG`

### Requirement: The training set writes its items as QTI 2.1
Every item in the training example set MUST carry QTI 2.1 markup in the `imsqti_v2p1` namespace, in the form the app's item editor writes: an `assessmentItem` with a `responseDeclaration`, and a `choiceInteraction` of `simpleChoice` options. No item MUST carry QTI 3.0 markup. The app's own choice reader MUST find every option of every item, with the correct answer among them.

#### Scenario: Reading a training item
- **GIVEN** the training set's item "BHV kennistoets, vraag 1"
- **WHEN** the app's choice reader reads its `qtiBody`
- **THEN** it finds three options, one of which is the item's correct response

### Requirement: A second example set does not duplicate a regulation code
Before a shipped example set is imported, the app MUST leave out each row of the `regulation` bucket whose code (`slug`) already exists in the learniq register under a different uuid. A row whose code exists under its own uuid MUST be kept. When the existing rows cannot be read, every row MUST be kept and the import MUST go on.

#### Scenario: The training set after the company set
- **GIVEN** the company set is loaded, with its own VCA and NIS2 rows
- **WHEN** the training set is loaded
- **THEN** its VCA and NIS2 rows are left out, its other regulations are imported, and no other bucket changes

#### Scenario: Loading the same set again
- **GIVEN** the training set is loaded
- **WHEN** it is loaded again
- **THEN** every one of its regulation rows is imported, so a changed row is updated

### Requirement: The wizard removes a loaded example set through OpenRegister's import jobs

The setup wizard MUST NOT offer a step that removes example data. The setup action `remove-example-set` stays on the server: its action MUST remove the example set stored as the wizard's answer by calling OpenRegister's `ConfigurationService::softDeleteAppImports()` with that set's import app id: `learniq.profile.<id>` for a shipped set, `learniq.demo` for the generated one. The call MUST be duck-typed: when the method does not exist, the action MUST NOT fail silently and MUST answer `success: false` with the `occ` command that removes the set instead (`php occ learniq:example-set:remove <id> --apply` for a shipped set). When no set was loaded (no answer, or "None"), or no import job was recorded, the action MUST say so and remove nothing. When OpenRegister reports errors, the action MUST answer `success: false`, name the count, and name `occ openregister:objects:purge --import-job <id>` to finish. A successful removal MUST keep the load step answered, so the wizard does not reopen.

#### Scenario: The wizard has no removal step

- **GIVEN** the setup wizard as the manifest declares it
- **WHEN** an administrator goes through it
- **THEN** its steps are welcome, example data, kind of organisation and done, and none removes data

#### Scenario: Removing the company set
- **GIVEN** the wizard loaded the company set and OpenRegister records one import job for `learniq.profile.corporate`
- **WHEN** an administrator posts the `remove-example-set` action
- **THEN** `softDeleteAppImports('learniq.profile.corporate')` is called once
- **AND** the answer says how many objects moved to the trash

#### Scenario: An OpenRegister without the method
- **GIVEN** OpenRegister's ConfigurationService has no `softDeleteAppImports`
- **WHEN** an administrator posts the `remove-example-set` action for the company set
- **THEN** the answer is `success: false` and names `php occ learniq:example-set:remove corporate --apply`

#### Scenario: Nothing was loaded
- **GIVEN** the wizard's example set answer is "None"
- **WHEN** an administrator posts the `remove-example-set` action
- **THEN** nothing is called and the answer says there is nothing to remove

### Requirement: The removal step never runs by itself
The setup status MUST report the `remove-example-set` step as done at all times, so the shared wizard neither starts it on entering the step (it auto-runs an outstanding run-action step) nor reopens itself for it (it opens while any optional step is outstanding). The step MUST run only when the admin clicks its button.

#### Scenario: Opening the wizard after loading a set
- **GIVEN** an example set was just loaded
- **WHEN** the setup status is read
- **THEN** `steps.remove-example-set.done` is true

### Requirement: Only an administrator or an administration manager chooses the kind of organisation
The wizard's segment answer MUST be written to `LearniqSettings.segment` only when the current user is in the `admin` or the `administration-managers` group, the groups the lane brief assigns to the segment. Any other caller MUST get a 403 with a reason, and nothing MUST be written.

#### Scenario: A delegated admin outside both groups
- **GIVEN** a user who may open the setup wizard but is in neither group
- **WHEN** they post `segment: corporate`
- **THEN** the answer is 403 and `setSegment` is not called

#### Scenario: An administration manager
- **GIVEN** a user in `administration-managers`
- **WHEN** they post `segment: po`
- **THEN** the segment is written with them as the one who set it

### Requirement: The register seeds reference rows only
`lib/Settings/learniq_register.json` MUST NOT carry example rows in `components.objects`. It MAY seed shared reference rows that the example sets point at by code (the `regulation` `AVG`). Learners, staff, courses, cohorts, programmes, enrolments and every other example row MUST live in a set under `lib/Settings/profiles/`, so an install holds only the set its admin picked, or nothing.

#### Scenario: A clean primary school install holds no company rows
- **GIVEN** a clean install where the admin loads only the primary school set
- **WHEN** the admin opens the courses, cohorts and learners lists
- **THEN** every row belongs to the primary school set
- **AND** there is no NIS2, AVG or BIO2 course, no "All Employees 2026" cohort and no learner Anna or Bram
- @e2e exclude register file shape, covered by PHPUnit `RegisterSeedObjectsTest`; checked live on a clean instance (po-parent-flows lane report)

### Requirement: The example portal offers the sign-in modes its audiences need

The portal learniq provisions for an example set MUST declare the sign-in modes the audiences in that set use: `nextcloud` where the set has pupils, workplace trainers or external assessors, and `digid` where it has guardians. Re-provisioning MUST NOT overwrite the modes of a portal that already exists, because a school may have chosen its own.

#### Scenario: A school with pupils and BPV
- GIVEN the mbo example set is loaded on a fresh instance
- WHEN its portal is provisioned
- THEN the portal offers `digid` and `nextcloud`
- @e2e exclude asserted on the provisioner, from the caller

#### Scenario: A portal the school already changed
- GIVEN a portal that offers only `digid`, chosen by the school
- WHEN the set is loaded again
- THEN its modes are left exactly as they are
- @e2e exclude as above

### Requirement: A test suite never edits a portal's sign-in modes

An e2e suite MUST NOT write a portal's `authentication.modes`. Where a suite needs a mode the portal does not offer, it MUST skip with a reason naming the portal and the mode.

#### Scenario: The instance does not offer the mode
- GIVEN a portal that offers only `digid`
- WHEN the pupil suite runs against it
- THEN it skips, naming the portal and the mode it needs
- @e2e exclude the skip is the suite's own behaviour, asserted by reading its result

### Requirement: Each example set is the school its portal was designed for

The curated example sets MUST carry the schools of the four portal designs: po MUST be "Basisschool De Wilgenboom", vo MUST be "Vaartveld College", mbo MUST be "Esdoornveen" and training MUST be the "Warmtepompacademie", each in the fictional town of Zuiddrecht. Each set MUST contain the people its design names, with the dates and numbers its boards show, pinned to Monday 5 October 2026. A number on a board MUST come out of the data (an average from its grade entries, an hours total from its hour weeks), not from a stored copy that disagrees with them.

#### Scenario: The po set holds the Hulstkamp family's autumn
- **GIVEN** a fresh instance
- **WHEN** the operator loads the po set
- **THEN** Fatima Hulstkamp is the guardian of Vera (Groep 7, po-leerkracht-09) and Sami (Groep 4, po-leerkracht-07)
- **AND** Sami has an undecided absence report for 5 October 2026 reading "Sami heeft buikgriep", marked by his teacher at 8.12
- **AND** for school year 2026-2027 Vera has 1 day absent and 1 late arrival of 10 minutes, Sami 2 days absent
- **AND** Vera's parent-evening time is Thursday 29 October 2026 18.00 to 18.10, acknowledged
- @e2e exclude seed data with no screen of its own, covered by PHPUnit `PrimarySchoolExampleSetTest::testTheWilgenboomStoryIsInTheData`; the portal screens over it are checked by `tests/e2e/portal-design/wilgenboom.spec.ts`

#### Scenario: The vo set holds Noor Bakker's Monday
- **GIVEN** a fresh instance
- **WHEN** the operator loads the vo set
- **THEN** Vaartveld College has a pupil Noor Bakker in class H4b with seven sessions on 5 October 2026, of which lesson 3 has a room change and lesson 7 is cancelled
- **AND** her wiskunde A grade entries 6,1 (weight 1), 4,7 (weight 3) and 5,8 (weight 1) average 5,2
- @e2e exclude seed data, covered by PHPUnit `SecondarySchoolExampleSetTest`

#### Scenario: The mbo set holds Milan de Groot's placement
- **GIVEN** a fresh instance
- **WHEN** the operator loads the mbo set
- **THEN** Esdoornveen has a student Milan de Groot in Mechatronica niveau 4 (crebo 25743) with a placement at Bakker Techniek BV whose agreed hours are 480
- **AND** his hour weeks give 96 hours approved, 16 waiting and 8 returned
- @e2e exclude seed data, covered by PHPUnit `VocationalCollegeExampleSetTest`

#### Scenario: The training set holds Jansen Installatietechniek's enrolments
- **GIVEN** a fresh instance
- **WHEN** the operator loads the training set
- **THEN** the Warmtepompacademie has Tom Verbeek, Youssef El Amrani and Sanne Kok enrolled for "F-gassen: herhaling en examen" on 8 October 2026, and Youssef has no birth date
- **AND** their F-gassen certificates expire on 30 November 2026
- @e2e exclude seed data, covered by PHPUnit `TrainingExampleSetTest`

#### Scenario: Reloading a renamed set moves no stored object
- **GIVEN** an instance that loaded the po set before this change
- **WHEN** the operator loads the po set again
- **THEN** every object keeps its uuid and slug, the story objects are added, and `occ learniq:example-set:remove po` still removes exactly the set's objects
- @e2e exclude covered by `python3 scripts/example-sets/po.py --check` and PHPUnit `ExampleSetDescriptorContractTest`

### Requirement: An administrator removes a loaded example set on the admin page

learniq's admin settings page MUST show an Example data section that lists every loaded example set (`example_sets_loaded`) by its label, read through the admin-only `GET /api/setup/example-sets`. Each set MUST have its own Remove button. A click MUST ask for confirmation first, and only a confirmation MUST post `POST /api/setup/action/remove-example-set-<id>`, which moves the set's objects to OpenRegister's trash. The section MUST show the answer's message as a success or an error, and MUST read the list again, so a set removed without errors disappears and a set with errors stays. With no set loaded, the section MUST say so.

#### Scenario: Removing the company set

- **GIVEN** the company and training sets are loaded
- **WHEN** the administrator clicks Remove next to "Company" and confirms
- **THEN** `POST /api/setup/action/remove-example-set-corporate` is sent
- **AND** the section shows how many objects moved to the trash
- **AND** only the training set is still listed

#### Scenario: The administrator changes their mind

- **GIVEN** the company set is loaded
- **WHEN** the administrator clicks Remove and cancels the question
- **THEN** nothing is posted and the set stays listed

#### Scenario: Nothing loaded

- **GIVEN** no example set was loaded
- **WHEN** the administrator opens the admin page
- **THEN** the Example data section says no example set is loaded
