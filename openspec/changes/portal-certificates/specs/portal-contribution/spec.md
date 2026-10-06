## ADDED Requirements

### Requirement: A certificate names its holder, its course and its renewal

A credential MUST carry `learnerName`, `courseName`, `organisationRef` (from the holder's learner profile), `validUntilLabel` ("Geldig tot" and the date of `expiresAt`) and `renewalLine` ("Herhaling op" and the first course day of the renewal enrolment while that enrolment is pending or active) as readable copies written on every save. It MUST carry `weeksUntilExpiry` and `expiryLabel` as OpenRegister calculations: "Verlopen" when `expiryStatus` is expired; "Verloopt deze week", "Verloopt over 1 week" or "Verloopt over N weken" while it is expiring or expiring-soon; "Geldig" otherwise.

#### Scenario: Tom's certificate on Monday 5 October 2026
- **GIVEN** Tom's F-gassen categorie 1 expires on 30 November 2026 and his renewal on 8 October is booked
- **WHEN** the certificate is read
- **THEN** it reads "Geldig tot 30 november 2026", "Herhaling op 8 oktober" and "Verloopt over 8 weken"
- @e2e exclude copies and calculation, covered by PHPUnit `CertificateCopiesTest`; the overview by `tests/e2e/portal-design/warmtepompacademie.spec.ts`

### Requirement: An employer sees her people's certificates, the first to expire first

The employer audience MUST declare `employerCertificates` over `credential`, scoped by `organisationRef`, filtered to issued certificates (`kind: certificate`), sorted by `expiresAt` ascending, without the signature or the signed payload. Her overview MUST show them as dated rows with the expiry status as a pill and `expiryLabel` beside it, and a page MUST list them as a table with the verification link.

#### Scenario: Linda sees the F-gassen certificates first
- **GIVEN** the training set
- **WHEN** Linda opens her overview
- **THEN** the three F-gassen categorie 1 certificates come before Sanne's BRL 6000-21, each with "Verloopt over 8 weken"
- @e2e tests/e2e/portal-design/warmtepompacademie.spec.ts
