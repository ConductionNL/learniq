---
kind: spec
depends_on: [employer-portal-audience]
---

# Proposal: academy-invoices-on-the-portal

## Why

The academy boards (8 October 2026) show invoices to the employer and to the administration:

- [warmtepompacademie/Documenten](https://identity.conduction.nl/screens/board?id=warmtepompacademie/Documenten): "Facturen: F-2026-1184, F-gassen: herhaling en examen, 3 deelnemers x [PRIJS], betalen voor 22 oktober 2026, Nog te betalen, Bekijken en betalen"; "F-2026-1122, Waterzijdig inregelen, betaald op 24 september 2026, Betaald, Downloaden".
- [warmtepompacademie/LqRolC](https://identity.conduction.nl/screens/board?id=warmtepompacademie/LqRolC): "Certificaten en facturen", "Facturen die aandacht vragen".
- [warmtepompacademie/Artikel](https://identity.conduction.nl/screens/board?id=warmtepompacademie/Artikel): "Prijs zonder btw, met examen en lunch. U betaalt op factuur."

The portal plan took deviation D-11: leave invoices out, show prices as `[PRIJS]`, because learniq retired its payment schemas (`lib/Repair/ArchiveRetiredPaymentObjects`) and invoices belong to a finance app. The analysis board ([warmtepompacademie/Nodig](https://identity.conduction.nl/screens/board?id=warmtepompacademie/Nodig)) asks "Waar wonen prijzen en facturen?".

## The decision (taken, design.md)

Ruben decided: **shillinq owns the invoice.** learniq supplies the enrolment (the booking) and the price; the portal shows the invoice through a shillinq contribution. learniq does not bring back an invoice schema.

## What changes in learniq

- **A price on a course**: `course.price` (amount excluding VAT, VAT rate, currency) and "met examen en lunch" as a price note, so the public page and the booking form can show a real price instead of `[PRIJS]`.
- **A booking asks for an invoice**: when an employer's booking is confirmed, learniq hands shillinq the enrolment and the price and asks it for an invoice (lines: course, course day, participants times price) and stores the invoice's reference and status on the booking (`invoiceRef`, `invoiceStatus`: `open`, `paid`, `overdue`, `credited`).
- **The employer reads her invoices**: a collection `employerInvoices` over her bookings' invoice references, with number, course, the participants line, the due date, the status in words and the document, read from shillinq. Paying happens in shillinq.
- **The administration's list** "Facturen die aandacht vragen": bookings whose invoice is overdue or not yet created after confirmation.

## Not in this change

- Payment itself, reminders, credit notes and the invoice document: shillinq.

## Dependency

This change depends on shillinq exposing a contribution that creates an invoice from an enrolment and a price and returns its reference, status and document link. The contract is confirmed in task T0.
