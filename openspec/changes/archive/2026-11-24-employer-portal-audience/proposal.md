---
kind: code
depends_on: [example-sets-are-the-four-schools, example-portal-declares-its-site, school-portals-use-the-new-blocks]
---

# Proposal: employer-portal-audience

## Why

The Warmtepompacademie's portal (school portal plan, wave 2, W2-2) is drawn for a company user: Linda Jansen of Jansen Installatietechniek BV sees the courses her installers are booked on, what still waits for her ("Vul de geboortedatum van Youssef El Amrani in, voor woensdag 12.00 uur"), and each booking with its steps and participants. learniq had none of it:

- no audience for a company: `getAudiences()` served pupils, guardians, workplace trainers and assessors;
- no company record: a client company was only a `department` string on its employees' profiles;
- no booking: an enrolment is one person, and the board lists one booking (inschrijving) with three people;
- no way for an employer to book, to name a participant, or to supply a birth date the exam institution needs.

## What changes

- **Two schemas.** `client-organisation` (a company that sends people: name, KvK number, eHerkenning reference, contact, the location whose editions it may book) and `course-booking` (one booking: the company, the edition, the number of places, and readable copies and a status the server writes).
- **New fields.** `LearnerProfile.organisationRef` (the employer) and `fullName` (readable copy). `Enrolment.organisationRef`, `learnerName`, `courseName` (readable copies), `bookingRef`, and the employer fields `detailsStatus`, `openTask`, `openTaskNote`, `openTaskDueAt`, `certificateLine`.
- **The `employer` audience** (`EmployerSitePages`), scoped by the `organisationRef` claim, every read a direct match:
  - the overview: greeting with "Medewerkers inschrijven", the open task as a highlight, the coming course days as dated rows with status and note;
  - "Inschrijvingen": every booking as a dated row, and the open booking with its five steps (a steps provider), its participants and its course day;
  - "Medewerkers": the company's people, names only.
- **Three forms** through learniq's own endpoints, the company stamped from the claim: book a number of places on an edition (`enrolEmployees`, editions from a second claim `editionLocationRef`), name an employee for a place (`addBookingParticipant`), supply a missing birth date (`supplyBirthDate`, write-only).
- **The booking says what waits.** `EmployerBookingFacts` derives a booking's day, time, place, trainer, people, status ("Wacht op u", "Ontvangen", "Bevestigd", "Afgerond") and note, and each participant's task. `EmployerBookingProjection` writes it after every employer write; `EmployerBookingCascade` queues it (deferred) when staff confirm an enrolment or fill in a birth date.
- **Invitation.** `occ learniq:portal:invite-employer <organisationRef> <organisation> [email]` provisions an `employer` account with the company's eHerkenning reference and writes `organisationRef`, `organisationName` and `editionLocationRef`. Portaliq shows `organisationName` in the session and keeps the account's audience on an eHerkenning sign-in (portaliq #1211, `the-account-names-the-audience-and-the-company`).
- **The training set** seeds Jansen Installatietechniek BV, its four employees, and the four bookings I-2026-0377, -0412, -0425 and -0431 with the copies the server would write; Linda gets a Nextcloud account in the declaration.

## Decisions

- **Two steps to book.** Portaliq's forms have no multi-select, and the board asks for the number of places first ("De namen vult u in de volgende stap in"). A booking is made for a number of places; each place gets its participant afterwards. A place without a name keeps the booking on "Wacht op u".
- **The birth date is write-only.** No employer collection projects it; she reads only "Geboortedatum ontbreekt". A date the institute already holds is never overwritten from the portal.
- **A booking's state follows its enrolments.** received until one enrolment is active, then confirmed; completed when all completed. The planner keeps confirming enrolments as before.
- **Birth date needed** when the course carries the tag `examen` or `certificaat` (the exam institution registers the participant). The story's herhaling gets the tag `examen`.
- **Trust `low`,** like the workplace trainer: the institute invites the employer and her rows are narrowed to her company. The birth date endpoint records nothing about assurance yet; raise to `substantial` when the proof instance's eHerkenning stub answers with it.
- **The list and the detail share one page.** Portaliq cannot link a list row to a record page; the open booking is chosen with the record switcher, the way the guardian's child page works.

## Deviations from the board

- The participant rows carry no inline birth-date field and no "Collega in zijn plaats" link: the birth date is one form below the participants (portaliq has no inline field on a row; a substitute is wave 3).
- No documents or invoice on the booking (no confirmation PDF exists; invoices are deviation D-11).
- No tabs "Komend / Afgerond / Geannuleerd" on the list (portaliq has no tabs over a rows display).
- The count stepper with the live total needs portaliq's `count` widget; until it lands the form asks for a number.

## Not in this change

- Certificates on the employer's pages (W2-4, `portal-certificates`).
- The participant's own portal (W2-6).
- The eHerkenning configuration of the example portal (W2-7).
