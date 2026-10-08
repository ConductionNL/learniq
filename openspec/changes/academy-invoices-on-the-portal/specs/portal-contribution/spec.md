## ADDED Requirements

### Requirement: An employer reads the invoices of her bookings

The employer audience MUST declare `employerInvoices` over her own organisation's bookings that carry an invoice reference, showing the invoice number, the course, the participants line, the due date, the status in words and a link to the document in the app that owns the invoice. A booking of another organisation MUST NOT be read.

#### Scenario: One open, one paid
- **GIVEN** Jansen Installatietechniek has F-2026-1184 open until 22 October and F-2026-1122 paid on 24 September
- **WHEN** Linda opens Certificaten en documenten
- **THEN** F-2026-1184 reads "Nog te betalen" and F-2026-1122 "Betaald", each with its document
- @e2e exclude depends on the open decision of design.md; spec-only proposal

### Requirement: A course carries its price

A course MUST be able to carry a price excluding VAT, a VAT rate and a price note; the public course page and the employer's booking form MUST show it when set, and MUST show nothing in its place when not set.

#### Scenario: No price set
- **GIVEN** a course without a price
- **WHEN** a visitor opens its public page
- **THEN** no price line shows
- @e2e exclude spec-only proposal
