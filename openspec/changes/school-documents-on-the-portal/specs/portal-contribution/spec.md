## ADDED Requirements

### Requirement: Each school audience offers its own documents, grouped per record

The parent, student and employer audiences MUST each declare a documents provider over the documents of their own records only: the guardian's children's published report cards and her own consent answers, grouped per child; the learner's own report cards, statements, signed agreements with their signing state, results and certificates; the employer's people's valid certificates. Every row MUST carry a group label, a title, a meta line in words and whether it is new; a draft or unpublished report card MUST NOT be offered.

#### Scenario: Fatima's documents
- **GIVEN** Vera and Sami each have two published report cards
- **WHEN** Fatima opens Rapporten en documenten
- **THEN** she reads a group "Groep 7" with Vera's two reports and a group "Groep 4" with Sami's, each "PDF, 2 pagina's"
- @e2e tests/e2e/portal-design/wilgenboom.spec.ts

#### Scenario: Milan's agreement waits on the company
- **GIVEN** Milan's POK is signed by Milan and the school, not yet by Bakker Techniek BV
- **WHEN** Milan opens Documenten
- **THEN** the POK reads "Wacht op leerbedrijf"
- @e2e tests/e2e/portal-design/esdoornveen.spec.ts

### Requirement: A pupil asks for a proof of enrolment

The student audience MUST offer `requestProofOfEnrolment`, which renders a proof of the learner's active enrolment for the current school year as a PDF and adds it to her documents; without an active enrolment it MUST be refused in words.

#### Scenario: For a side job
- **GIVEN** Noor is enrolled in 4 havo for 2026-2027
- **WHEN** she asks for a proof of enrolment
- **THEN** "Bewijs van inschrijving, schooljaar 2026-2027" appears among her documents
- @e2e exclude render path asserted in the service's unit tests; spec-only proposal

### Requirement: An employer downloads every valid certificate at once

The employer audience MUST offer one download that bundles the certificates of her people that are issued and not expired, and only those.

#### Scenario: Linda downloads
- **GIVEN** four valid certificates and one expired one in her company
- **WHEN** Linda chooses "Alle geldige certificaten downloaden"
- **THEN** she receives one file with four certificates
- @e2e exclude bundle contents asserted in unit tests; spec-only proposal

### Requirement: A learner adds a document to her own dossier

The student audience MUST offer `uploadStudentDocument`, a create action on `learner-document` that takes a title, an optional note and one file, with the learner taken from her sign-in over any client value. Her Documents page MUST list what she added under "Added by you" in the group of her current enrolment only (her active enrolment enrolled most recently), so a learner with two enrolments reads each document once; another learner's document MUST NOT be listed.

#### Scenario: Milan adds his first-aid diploma
- **GIVEN** Milan has one active enrolment
- **WHEN** he adds "EHBO-diploma" with its PDF from Documenten
- **THEN** "EHBO-diploma" appears under "Added by you" in the group of that enrolment
- @e2e exclude listing asserted in LearnerDocumentsTest; the upload is portaliq's create action

#### Scenario: Two enrolments, one listing
- **GIVEN** a learner with an active enrolment from 2025 and one from 2026, and one document of her own
- **WHEN** she opens Documenten
- **THEN** the document appears only in the 2026 group
- @e2e exclude asserted in LearnerDocumentsTest
