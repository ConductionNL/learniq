## ADDED Requirements

### Requirement: The parent audience reads the grades on the child's published report cards
The parent manifest MUST declare a `parentReportCardGrades` collection over `report-card` that reads through the same reverse scope-value `via` join as every parent read collection (REQ-PCON-004/005), scoped on `learnerRef`, at `minTrust: substantial`. It MUST carry the server-side filter `lifecycle: published-to-parents`, so a report card in `draft`, `rapportvergadering-review` or `finalised` is never read for a guardian. It MUST project only `learnerRef`, `periodName` and `gradeLines`, and show `periodName` and `gradeLines` as its columns, never the nested `subjectGrades` with its uuids. No parent collection MUST read pupil tracking results (`lvs-result`). Adding it MUST leave every other collection of the parent manifest, and the manifests of every other audience, unchanged.

#### Scenario: A primary school guardian sees her child's report card grades
@e2e tests/e2e/po-parent-flows.spec.ts "e. the guardian reads the grades on her child's published report cards, never a draft"
- **GIVEN** Fatima Hulstkamp is the guardian of Vera, whose two report cards are published to parents
- **WHEN** she opens "My child's report card grades" in the portal
- **THEN** she sees one row per report card with the period ("Rapport 1") and the grades ("Rekenen: 7,9; Taal: 8,3; …")
- **AND** no row of another child

#### Scenario: A draft report card never reaches the guardian
@e2e tests/e2e/po-parent-flows.spec.ts "e. the guardian reads the grades on her child's published report cards, never a draft"
- **GIVEN** the group teacher starts a new report card for Vera, which stays a draft
- **WHEN** the guardian reads "My child's report card grades"
- **THEN** the draft is not among the rows

#### Scenario: The other audiences are untouched
@e2e exclude Manifest shape; pinned by tests/Unit/Portal/PortalContributionProviderTest.php testParentReadsTheGradesOnPublishedReportCards and testParentManifestShape, and by a byte-for-byte JSON comparison of every audience's manifest before and after (tasks.md 1.6).
- **WHEN** the student, praktijkopleider or external-assessor manifest is built
- **THEN** it is identical to the manifest before this change
