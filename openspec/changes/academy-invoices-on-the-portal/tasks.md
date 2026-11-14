# Tasks: academy-invoices-on-the-portal

- [ ] **T0**: confirm the shillinq contribution contract: create an invoice from an enrolment and a price, return reference, status and document link, emit status changes
  - Checked 2026-11-14 (design.md, T0 finding): the contribution raise contract creates the invoice and returns its reference, but writes it VAT-exempt, returns no document link and signals only settled. Needs a shillinq change before T2.
- [x] **T1**: register: `course.price`, `vatRate`, `priceNote`; booking `invoiceRef`, `invoiceStatus`
- [ ] **T2**: on a confirmed booking, hand shillinq the enrolment and price and ask for an invoice
- [ ] **T3**: `employerInvoices` collection (read from shillinq); the administration's list of invoices needing attention
- [ ] **T4**: training example set: the two invoices of the board
