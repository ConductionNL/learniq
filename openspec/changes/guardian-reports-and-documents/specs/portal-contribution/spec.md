## ADDED Requirements

### Requirement: A guardian reads her children's published reports and the school's papers on one page

The parent audience MUST declare a page `parentDocuments` with a documents block for each record of `parentChildren`, headed by the child's first name with the group line beside it, and one for each record of `parentSchools`, headed "Van school" with "Voor alle ouders" beside it. `parentChildren` MUST declare the documents provider `childDocuments`, which MUST answer only the files attached to that child's report cards in `published-to-parents`. `parentSchools` MUST read only the schools named in `schoolId` by the guardian's own children, at `minTrust: substantial`, and MUST declare `schoolDocuments`, which MUST answer only the files attached to that school's `school-document` records that are `published` and for `guardians`. Both providers MUST check every record they read against their filters again before answering, and MUST answer nothing for an empty id or a read that fails. The po portal MUST list the page under "Van school" after "Nieuws".

#### Scenario: Fatima reads Vera's report and the school guide
- GIVEN Vera has a report card in `published-to-parents` with "Rapport 1, februari 2026.pdf" attached and one in `rapportvergadering-review` with a PDF attached
- AND De Wilgenboom has a published school document for guardians with "Schoolgids 2026-2027.pdf" and a draft one
- WHEN Fatima opens "Rapporten en documenten"
- THEN under "Vera" she reads "Rapport 1, februari 2026" and nothing from the card in review
- AND under "Van school" she reads "Schoolgids 2026-2027" and not the draft
- @e2e exclude provider and manifest logic, covered by PHPUnit `GuardianDocumentsTest` and `ParentDocumentsPageTest`; the page itself is portaliq's `documents-for-each-record`

#### Scenario: Another child's report
- GIVEN Sami is not Fatima's child
- WHEN a request names Sami's learner profile on her documents route
- THEN portaliq answers 404 before learniq is asked
- @e2e exclude enforced by portaliq's PortalCollectionDocumentsController scoped read, covered by its own tests
