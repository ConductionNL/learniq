# Design: academy-invoices-on-the-portal

## Decision needed: who owns prices and invoices

| Option | What it means | For | Against |
|---|---|---|---|
| A. shillinq owns invoices (recommended) | learniq keeps the course price and asks shillinq for an invoice per confirmed booking; the portal reads the invoice from shillinq through learniq's collection | one finance app for the fleet; VAT, numbering, reminders and payment exist once; matches D-11's reason | a cross-app call per booking; the academy needs shillinq installed |
| B. learniq owns invoices again | learniq brings back an invoice schema for bookings | no second app | reverses the retirement of learniq's payment schemas; numbering and VAT duplicated |
| C. Keep D-11 | no invoices; prices stay `[PRIJS]` | nothing to build | the academy boards cannot match |

Until Ruben decides, the build stops at the course price and the booking's invoice fields; nothing calls an app.

## The seam

learniq stores only `invoiceRef` and `invoiceStatus` on the booking. The invoice document, its lines and its payment live in the owning app. A status change reaches learniq by event (option A) or is learniq's own (option B).
