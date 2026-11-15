# Example sets Specification

## ADDED Requirements

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
