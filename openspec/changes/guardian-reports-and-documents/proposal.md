---
kind: code
---

# Proposal: guardian-reports-and-documents

## Why

The guardian's menu on [wilgenboom/MijnMenu](https://identity.conduction.nl/screens/board?id=wilgenboom/MijnMenu) has "Rapporten en documenten" under "Van school", and [wilgenboom/Documenten](https://identity.conduction.nl/screens/board?id=wilgenboom/Documenten) draws the page it opens: "De rapporten van uw kinderen en de papieren van school. U kunt alles downloaden en bewaren.", a group per child ("Vera", "Groep 7") with her reports, and a group "Van school" ("Voor alle ouders") with the jaarkalender and the schoolgids. Round 3 left the menu item out because learniq had no page to open (lq-r3 lane log).

`school-documents-on-the-portal` describes this page for every school audience. This change builds the guardian's part of it.

## What changes

- **The page.** The parent audience gains `parentDocuments`, "Reports and documents" (Dutch "Rapporten en documenten"): an opening line, a portaliq documents block for each child in `parentChildren` (headed by the child's first name with the group line beside it) and one for each school in `parentSchools` ("Van school", "Voor alle ouders"). The po portal puts it in its menu under "Van school", after "Nieuws".
- **A child's reports.** `parentChildren` declares the documents provider `childDocuments`: the files attached to that child's report cards in `published-to-parents`, titled by the card's period. A card in draft, in review or finalised gives nothing.
- **The school's papers.** A new record, `school-document` (register 0.47.0): a school's paper with its file attached, its school, who it is for (`guardians` or `staff`, default `staff`) and whether it is published (default `draft`). `parentSchools` reads the schools of the guardian's children (the reverse join on learner-profile, `targetField: schoolId`, matched on the school's own id) and declares `schoolDocuments`: the files on that school's published documents for guardians.
- **Access before anything leaves the server.** Portaliq proves the child or the school is the guardian's through the collection's scoped read before it calls the provider, for the list and again for every download. The provider reads with the query's filters and checks each row against them again, so a store that ignores a filter cannot widen what she gets.
- A document is "Nieuw" for fourteen days after its file was saved.

### A call this change makes

`school-documents-on-the-portal` put the school's papers in portaliq media for the guardian audience. Portaliq's `documents-grouped-per-record` task 3 found that media has no audience field and no signed-in read, and who counts as a guardian of a school is a learniq fact: one portal can serve several schools. So the papers are learniq records, read through the guardian's children like the school calendar already is.

## Capabilities

### Modified capabilities

- `portal-contribution`: ADDED requirement for the guardian's reports and documents page.

## Impact

- `lib/Portal/ParentDocumentsPage.php`, `GuardianDocuments.php`, `PortalFileEntries.php`, `PaperQuery.php`, `PortalRecordProviders.php` (new); `PortalContributionProvider.php` (`childDocuments`, `schoolDocuments`; the per-row services now come in one `PortalRecordProviders`), `ParentRecordPage.php`, `PortalLabelTranslator.php` (`subtitle`, `emptyLabel`, `markdown`).
- `lib/Settings/learniq_register.json`: `SchoolDocument` 0.1.0, register 0.47.0.
- `lib/Settings/portals/po.json`: the menu item. A portal that already exists keeps its menu (the provisioner only writes what is missing).
- l10n: English and Dutch.
- Needs portaliq `documents-for-each-record`.

## Not in this change

- "Door u aangeleverd": what a guardian hands in needs an upload action and a record for it.
- "Alle rapporten van Vera downloaden": portaliq has no bundle endpoint yet.
- The line about the next report ("Het eerste rapport van dit schooljaar komt op vrijdag 12 februari 2027"): needs the next report period's date.
- A staff page to add school documents: no board draws it; staff add them in OpenRegister for now.
- The report's PDF on the card: `report-card-pdf-to-guardians` task 1.2 attaches it after a render. Until then a card shows once someone attached its PDF.
