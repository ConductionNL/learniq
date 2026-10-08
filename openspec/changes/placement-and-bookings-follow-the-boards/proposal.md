---
kind: spec
depends_on: [internship-hours, employer-portal-audience, portal-certificates]
---

# Proposal: placement-and-bookings-follow-the-boards

## Why

Proof run 2 (08 Oct, REPORT-2 items 8 and 9) put Esdoornveen's placement page and the academy's employer pages next to their boards. The placement record showed field keys ("Period From", "Lifecycle State"). Each step's date showed twice, and the hours bar was missing. A booking's participants were a plain table without the birth-date line and its pill. The certificates were listed per person, where the board lists them per certificate.

## What changes

- **Placement:** `studentBpvPlacements` declares a label for every field it projects, and words for its states (`PortalValueLabels::PLACEMENT_STATUS`). The placement page shows the hours bar under "Waar sta je?". The first step no longer carries a date beside the line that already names the day.
- **Booking:** the participants of an opened booking show as rows. Each row has the name, the certificate line ("Certificaat geldig tot 30 november 2026") and a pill for the details ("Gegevens compleet", "Geboortedatum ontbreekt"). The form "Geboortedatum invullen" stays under them.
- **Certificates:** `employerCertificates` groups its rows per certificate (`groupByField: courseName`).

## Not in this change (no schema or no portaliq support)

- **Placement:**
  - The "Volgende stap" card needs a highlight block fed by the steps provider (portaliq).
  - "Werkprocessen" with hours and the student's own estimate: `werkproces-assessment` holds the assessor's judgement only. There is no schema for hours per werkproces or a self-assessment.
  - "Je begeleiders": the placement holds `practicalTrainerId` and `schoolCoachId`. Their names need a portaliq lookup keyed on a row field, or readable copies on the placement.
  - "Afspraken": there are no fields for workdays, the address, or the programme's crebo on the placement.
- **Overview:** the employer overview's certificate rows stay per person. Merging holders into one row per certificate needs a summary per certificate.
- **Academy course rows** (capacity, meta, links): waiting on the fields the public index must send (asked through the coordinator).
