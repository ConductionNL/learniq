# Proposal: certificates-group-the-first-to-expire-first

## Why

live-learniq-1 (25 Nov 2026, 20:32Z): on the warmtepompacademie portal "Certificaten" shows BRL 6000-21 (2028) above F-gassen (2027). learniq sorts `employerCertificates` on `expiresAt` ascending and groups it per `courseName`, and portaliq ordered the groups by heading, so the requirement *An employer sees her people's certificates, the first to expire first* did not hold. Ruben decided (25 Nov 2026 21:50 CET): portaliq gets an opt-in `groupOrder: 'rows'` (portaliq collection-group-order, ConductionNL/portaliq#2824), learniq's certificates declare it.

## What changes

- `employerCertificates` declares `groupOrder: 'rows'`: each certificate's group stands where its first holder stands in the expiry order. An older portaliq drops the key and keeps ordering by heading.

## Out of scope

- The portaliq order itself (portaliq#2824).
