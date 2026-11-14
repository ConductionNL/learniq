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

## T0 finding (2026-11-14): the existing contract does not fit yet

Read at shillinq development on 2026-11-14. The nearest contract is shillinq's
school contribution raise (`POST /apps/shillinq/api/contributions/raise`,
`ContributionRaiseService::raise()`, contract version 1 of
`extracurricular-fee-to-shillinq`), which learniq already calls for fee items
through `ShillinqContributionClient`. It creates one issued invoice and one
payment request per recipient for a chargeable, and returns the invoice id and
number. For a booking it falls short in three places:

1. **VAT.** `ContributionInvoiceBuilder` writes every contribution invoice as
   exempt (`vatCategory` E, `vatRate` 0, `vatAmount` 0: education). An academy
   course carries `vatRate` (T1), so a booking invoiced through this call would
   leave out the VAT it owes.
2. **Document link.** The response names `invoiceId`, `invoiceNumber` and
   `paymentRequestId`, no link to the invoice document the employer opens.
3. **Status.** The only signal is the settled edge (`settledAt`) on the payment
   request. `invoiceStatus` needs `overdue` and `credited` as well.

T2 to T4 wait for shillinq to accept a VAT rate per charge (or a booking kind
that is not exempt), to return a document link, and to signal overdue and
credited. Nothing in learniq can work around the first point without writing
wrong invoices.
