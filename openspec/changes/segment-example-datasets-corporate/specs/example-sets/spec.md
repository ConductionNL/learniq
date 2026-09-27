# Example sets Specification

## ADDED Requirements

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
