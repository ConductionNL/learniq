# Design: academy-invoices-on-the-portal

## Decision: shillinq owns prices and invoices (decided)

Ruben decided for option A. The options considered:

| Option | What it means | Outcome |
|---|---|---|
| A. shillinq owns invoices | learniq keeps the course price and supplies the enrolment and price; the portal shows the invoice through a shillinq contribution | **Decided.** One finance app for the fleet; VAT, numbering, reminders and payment exist once; matches D-11's reason |
| B. learniq owns invoices again | learniq brings back an invoice schema for bookings | Rejected: reverses the retirement of learniq's payment schemas; numbering and VAT duplicated |
| C. Keep D-11 | no invoices; prices stay `[PRIJS]` | Rejected: the academy boards cannot match |

Consequence: the academy needs shillinq installed, and each confirmed booking costs one cross-app call.

## The seam

learniq supplies the enrolment and the price. It stores only `invoiceRef` and `invoiceStatus` on the booking. The invoice document, its lines and its payment live in shillinq. A status change reaches learniq by event from shillinq. The portal shows the invoice through a shillinq contribution, so learniq has no invoice schema.

## Dependency

The shillinq contribution contract (create an invoice from enrolment and price; return reference, status and document link; emit status changes) is confirmed in T0 before T2 is built.
