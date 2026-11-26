## MODIFIED Requirements

### Requirement: An employer sees her people's certificates, the first to expire first

The employer audience MUST declare `employerCertificates` over `credential`, scoped by `organisationRef`, filtered to issued certificates (`kind: certificate`), sorted by `expiresAt` ascending, without the signature or the signed payload. Her overview MUST show them as dated rows with the expiry status as a pill and `expiryLabel` beside it, and a page MUST list them as a table with the verification link, which reads "Controleren" (`linkLabel`) and not the bare address. The table groups the certificates per `courseName` and MUST declare `groupOrder: 'rows'`, so the group of the certificate that expires first stands on top.

#### Scenario: Linda sees the F-gassen certificates first
- **GIVEN** the training set
- **WHEN** Linda opens her overview
- **THEN** the three F-gassen categorie 1 certificates come before Sanne's BRL 6000-21, each with "Verloopt over 8 weken"
- @e2e tests/e2e/portal-design/warmtepompacademie.spec.ts

#### Scenario: The Certificaten table puts the F-gassen group on top
- **GIVEN** the training set
- **WHEN** Linda opens "Certificaten"
- **THEN** the F-gassen categorie 1 group stands above the BRL 6000-21 group
- **AND** each row links to its certificate's check page as "Controleren"
- @e2e tests/e2e/portal-design/warmtepompacademie.spec.ts
