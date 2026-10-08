---
kind: spec
depends_on: [training-provider-course-days, portal-certificates]
---

# Proposal: exam-registration-and-certificate-issue

## Why

The academy's administration ([warmtepompacademie/LqRolC](https://identity.conduction.nl/screens/board?id=warmtepompacademie/LqRolC), 8 October 2026) opens on: "De aanmelding voor het examen van donderdag sluit woensdag om 12.00 uur. 10 van de 11 deelnemers zijn compleet. Van Youssef El Amrani (Jansen Installatietechniek) ontbreekt de geboortedatum. De werkgever kreeg vanochtend bericht." with "Linda Jansen bellen" and "Aanmeldlijst openen"; then "Uit te geven: alle afgeronde cursusdagen", "Certificaten die verlopen voor 1 januari" (deelnemer, certificaat, verloopt, herhaling) and "Komende examens". [warmtepompacademie/LqDetail](https://identity.conduction.nl/screens/board?id=warmtepompacademie/LqDetail): "Gegevens voor het examen zijn compleet", "Daarna: Rob Maas voert de uitslag in en geeft het certificaat uit".

The analysis board ([warmtepompacademie/Nodig](https://identity.conduction.nl/screens/board?id=warmtepompacademie/Nodig)) lists "Aanmelding bij de exameninstelling: met de geboortedatum als afgeschermd gegeven en een sluitmoment" under what learniq must know, and asks "Wie meldt aan voor het examen?" (the design assumes the administration). `employer-portal-audience` asks the employer for the birth date; nothing gathers the registration list for an external examining body, closes it, or issues the certificates of a finished day at once (`certification` has a bulk reissue, not a first issue after results).

## What changes

- **An exam registration list** per exam sitting of a course day with an external examining body: new schema `exam-registration` (course day, examining body name, `closesAt`, lifecycle `open`, `closed`, `sent`), with one line per participant and a completeness check on the fields the body requires (default: full name, birth date, place of birth). The birth date is read from the learner profile and never copied onto the list's public parts; only the export to the body carries it.
- **Incomplete participants** are named, and their employer receives a portal task "Geboortedatum invullen" (the employer action exists in `employer-portal-audience`).
- **Closing**: at `closesAt` the list closes; the administration exports it (CSV in the body's column order) and marks it sent.
- **Issue after results**: for a course day in rounding off, "Certificaten uitgeven" issues a certificate to every participant with a passing result in one action, recording who issued and when; a participant without a result is named and skipped.
- **Expiring before a date**: a staff list of issued certificates expiring before a chosen date with the renewal booking, if any.

## Decision for Ruben

Who registers for the exam: the design's answer (the administration, with a list that closes at a fixed moment) is taken here. If participants or employers register themselves, the list stays and the actor changes.

## Not in this change

- An API connection to an examining body. The export is a file.
- Invoices: `academy-invoices-on-the-portal`.
