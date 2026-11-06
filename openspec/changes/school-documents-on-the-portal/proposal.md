---
kind: spec
depends_on: [report-card-pdf-to-guardians, portal-certificates]
---

# Proposal: school-documents-on-the-portal

## Why

Every school portal has a "Documenten" page on the boards (8 October 2026), and none of them can be filled from learniq today: no learniq collection offers a documents provider (portal plan, section 1.3 row "Documenten").

- [wilgenboom/Documenten](https://identity.conduction.nl/screens/board?id=wilgenboom/Documenten): "De rapporten van uw kinderen en de papieren van school", grouped "Groep 7" (Vera's reports 2 and 1 of groep 6), "Groep 4" (Sami's) and "Van school" (jaarkalender, schoolgids, "Uw toestemming voor foto's en video, door u ingevuld op 28 augustus 2026"), with "Nieuw" and a note "Het eerste rapport van dit schooljaar komt op vrijdag 12 februari 2027".
- [vaartveld/Documenten](https://identity.conduction.nl/screens/board?id=vaartveld/Documenten): the pupil's own reports ("Rapport 2, 3 havo, Over naar 4 havo"), "Toetsen en examen" (toetsrooster toetsweek 1, PTA), "Keuzes en verklaringen" (profielkeuze, "Bewijs van inschrijving, voor je bijbaan of ov-kaart"). "Je ouders zien dezelfde documenten."
- [esdoornveen/Documenten](https://identity.conduction.nl/screens/board?id=esdoornveen/Documenten): agreements with a signing state (praktijkovereenkomst, onderwijsovereenkomst), results, and certificates; "Document toevoegen" by the student.
- [warmtepompacademie/Documenten](https://identity.conduction.nl/screens/board?id=warmtepompacademie/Documenten): the employer's certificates "Per medewerker" with "Alle geldige certificaten downloaden"; invoices follow in `academy-invoices-on-the-portal`.

The report PDF itself is `report-card-pdf-to-guardians`; certificates for the employer are `portal-certificates`. This change offers them, and the other school papers, as documents grouped per record. The grouped page is portaliq's `documents-grouped-per-record`.

## What changes

- **Guardian**: `parentDocuments`, a documents provider over her children's published report cards (the attached PDF), the consent records she gave (`course-share-consent` and `permission-slips` answers, as a generated summary PDF), grouped per child by the child's group name. School papers ("Van school") are portaliq media for the guardian audience, not learniq's.
- **Pupil (vo)**: `studentDocuments`: her own published report cards, her approved `subject-choice` as a statement, and a proof of enrolment generated on request from her active enrolment (`requestProofOfEnrolment`, a PDF through filinq's render endpoint, as the report card does). Her guardians read the same rows through `parentDocuments`.
- **Student (mbo)**: `studentDocuments` adds the signed POK (`poksignature` state as the status pill: "Ondertekend door alle drie", "Wacht op leerbedrijf"), her exam results as a generated list, and her certificates; `uploadStudentDocument` lets her add a document to her own dossier.
- **Employer (training)**: `employerCertificatesDownload`, one ZIP of the verification PDFs of every valid certificate of her people (portal-certificates left it out).
- Each document row carries `group`, `title`, `meta` ("Groep 6, PDF, 2 pagina's"), `isNew` (published in the last 14 days and not opened) and an optional status.

## Not in this change

- The school papers (schoolgids, jaarkalender, PTA as a file): editorial media in portaliq, placed by the provisioner with an audience.
- A diploma: DUO's diploma register holds it, as the Esdoornveen board says.
