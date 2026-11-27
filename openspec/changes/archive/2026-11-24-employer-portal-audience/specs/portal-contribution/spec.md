## ADDED Requirements

### Requirement: An employer reads only her own company's people and bookings

Learniq MUST serve an `employer` audience. Every employer collection MUST be scoped by `scopeField: organisationRef` with `scopeClaim: organisationRef`, except the editions she may book, which MUST be scoped by the cohort's `locationId` and the claim `editionLocationRef`. No employer collection MUST use a `via` join, and no employer collection MUST project a birth date. An enrolment MUST carry `organisationRef`, `learnerName` and `courseName` as readable copies taken from its learner profile and course on every save.

#### Scenario: Linda sees Jansen's bookings and nobody else's
- **GIVEN** Linda's portal account holds `organisationRef` of Jansen Installatietechniek BV
- **WHEN** she opens Mijn academie
- **THEN** she reads the bookings and participants of Jansen only
- @e2e tests/e2e/portal-design/warmtepompacademie.spec.ts

### Requirement: An employer lands on what waits for her

The employer's home page MUST open with the greeting and the button to book places, then the participants whose details are missing as a highlighted task with the sentence, the reason and the time it is due, then the coming bookings as dated rows with course, participants, status and note.

#### Scenario: The overview asks for Youssef's birth date
- **GIVEN** the training set is loaded
- **WHEN** Linda opens her overview on Monday 5 October 2026
- **THEN** she reads "Vul de geboortedatum van Youssef El Amrani in" and the three coming bookings, I-2026-0412 with "Geboortedatum van 1 deelnemer ontbreekt"
- @e2e tests/e2e/portal-design/warmtepompacademie.spec.ts

### Requirement: A booking tells the employer what still waits for her

A booking's readable copies (course, day line, time, place, trainer, participants), its `lifecycle`, `employerStatus`, `statusNote` and `detailsDueAt`, and each participant enrolment's `detailsStatus`, `openTask`, `openTaskNote`, `openTaskDueAt` and `certificateLine` MUST be written by the server from the rows the booking is made of. `employerStatus` MUST be `waiting-for-you` while a place has no participant or a participant lacks a birth date the course needs (a course tagged `examen` or `certificaat`). `detailsDueAt` MUST be 12.00 on the working day before the first course day. The booking MUST be re-derived after every employer write, and after staff change a participant's enrolment state, birth date or name, without slowing the save (deferred). A booking page MUST show five steps: booked, confirmed, details complete, course day, result and certificate.

#### Scenario: A confirmed exam booking waits for one birth date
- **GIVEN** booking I-2026-0412 has Tom, Youssef and Sanne, all enrolments active, and Youssef has no birth date
- **WHEN** the booking is derived
- **THEN** it reads "Wacht op u", "Geboortedatum van 1 deelnemer ontbreekt", due Wednesday 7 October 12.00, and its steps stand at "Gegevens compleet"
- @e2e exclude server derivation, covered by PHPUnit `EmployerBookingFactsTest`; the page by `tests/e2e/portal-design/warmtepompacademie.spec.ts`

#### Scenario: The planner confirms an enrolment in Nextcloud
- **GIVEN** a received booking
- **WHEN** the planner activates one of its enrolments
- **THEN** after the request the booking reads "Bevestigd"
- @e2e exclude deferred listener, covered by PHPUnit `EmployerBookingCascadeTest`

### Requirement: An employer books places and names her participants

The employer MUST be able to book 1 to 12 places on a planned edition of the location her account names, and then name one of her company's employees per place. Both MUST go through learniq's endpoint with the company stamped from the claim. A person of another company, a person already on the booking, a place too many, an edition of another location or one that is no longer planned MUST be refused and write nothing.

#### Scenario: Linda books two places and names Tom
- **GIVEN** a planned F-gassen edition at the Praktijkhal
- **WHEN** Linda books two places and puts Tom on the booking
- **THEN** the booking reads "Vul de naam van 1 deelnemer in"
- @e2e exclude endpoint rules, covered by PHPUnit `PortalEmployerBookingsTest`

### Requirement: An employer supplies a missing birth date and never reads it

The employer MUST be able to store a birth date for one of her company's participants whose profile has none. The value MUST be a real date between 100 and 14 years ago. A date the institute already holds MUST NOT be overwritten, and the answer MUST NOT carry the date back. After the write every booking of that participant MUST be re-derived.

#### Scenario: Linda fills in Youssef's birth date
- **GIVEN** Youssef's profile has no birth date
- **WHEN** Linda sends 14 March 1994 for him
- **THEN** his profile holds 1994-03-14 and booking I-2026-0412 no longer waits for her
- @e2e exclude endpoint rules, covered by PHPUnit `PortalEmployerBookingsTest`
