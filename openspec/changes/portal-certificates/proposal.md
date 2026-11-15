---
kind: code
depends_on: [employer-portal-audience]
---

# Proposal: portal-certificates

## Why

The Warmtepompacademie board shows the certificates in Linda's company on her overview and on their own page (school portal plan, wave 2, W2-4): "F-gassen categorie 1, Tom Verbeek, Geldig tot 30 november 2026, Herhaling staat op 8 oktober, Verloopt over 8 weken"; "BRL 6000-21, Sanne Kok, Geldig". learniq's `credential` holds the expiry (`expiresAt`, and OpenRegister's `daysUntilExpiry` and `expiryStatus`) but was staff only: no portal read it, and a row named its holder and course only by uuid.

## What changes

- **Readable copies on a certificate** (`CertificateCopies`, through the readable-copy stamp): `learnerName`, `courseName`, `organisationRef` (the holder's employer), `validUntilLabel` ("Geldig tot 30 november 2026") and `renewalLine` ("Herhaling op 8 oktober", from the renewal enrolment while it is still coming).
- **Two calculations OpenRegister keeps fresh** (materialised, refreshed by its temporal sweep): `weeksUntilExpiry` and `expiryLabel` ("Verloopt over 8 weken", "Verloopt over 1 week", "Verloopt deze week", "Verlopen", "Geldig"). The label changes every week by itself; a stamped copy could not.
- **The employer reads her people's certificates**: `employerCertificates` (issued certificates only, the first to expire first), as dated rows on her overview (pill from `expiryStatus`, the words from `expiryLabel`, the renewal under it) and as a table on "Certificaten" with the verification link.
- **The training set** seeds the copies on every certificate.

## Decisions

- Only `kind: certificate` and `lifecycle: issued`. A proof of participation is a badge and belongs to the booking; a revoked or expired certificate is history (the board: "Daan Visser heeft nog geen certificaat").
- The download is the certificate's public `verificationUrl`. learniq has no rendered PDF of a certificate; the verification page is what an inspector or an employer can open and print.
- The expiry words are Dutch data in the register, like the other readable copies. The pill (`expiryStatus`) reads through the portal's translated value labels.

## Not in this change

- "Alle geldige certificaten downloaden" as one file.
- The participant's own certificates: they come with the participant's portal (W2-6).
- Grouping several holders under one certificate row (the board's overview): one row per holder.
